<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStateException;
use App\Models\ActivityLog;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\ReturnOrder;
use App\Models\Order\ReturnOrderItem;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShipmentItem;
use App\Models\User;
use App\Support\Money;
use App\Support\SequenceNumberRetry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single implementation of the return (RMA) lifecycle: create, approve,
 * adjust lines (restock and condition), receive (restock), complete and
 * reject.
 *
 * Shared by the web ReturnOrderController and the REST API so the quantity
 * caps, locking and restock behaviour cannot drift between surfaces. State
 * violations throw InvalidStateException; per-line quantity violations throw
 * a ValidationException keyed by `items.{index}.*`.
 *
 * Warehouse access: a return belongs to the warehouses its goods go back to,
 * i.e. the primary location of each line's product (where receive() books
 * the restock). A restricted user sees and acts on a return when any line
 * comes back to their warehouses, and may only raise a return, or receive
 * one, when every line it restocks lands in their warehouses. The origin
 * order is not used: orders carry no location, and the restock location is
 * what the stock movement actually touches.
 */
final class ReturnOrderService
{
    public function __construct(
        private readonly TrackedStockAllocationService $trackedStock,
        private readonly WarehouseAccessService $warehouseAccess,
    ) {}

    /**
     * Narrow a return_orders query to returns with at least one line coming
     * back to a warehouse the user can access. A no-op when unrestricted.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function scopeForUser($query, User $user)
    {
        if (! $this->warehouseAccess->isRestricted($user)) {
            return $query;
        }

        return $query->whereHas('items.product', function ($products) use ($user) {
            $this->warehouseAccess->scopeByLocation($products, $user, 'products.location_id');
        });
    }

    /**
     * Orders a return can be raised against, for the "New Return" picker:
     * not cancelled, and shipped or delivered or with a shipment that has
     * left the warehouse (the rule create() enforces). A restricted user
     * sees only orders with goods from their warehouses.
     */
    public function returnableOrders(User $user, ?string $search = null): Builder
    {
        $query = Order::query()
            ->forOrganization($user->organization_id)
            ->where('status', '!=', OrderStatus::CANCELLED->value)
            ->where(function (Builder $q): void {
                $q->whereIn('status', [OrderStatus::SHIPPED->value, OrderStatus::DELIVERED->value])
                    ->orWhereHas('shipments', fn (Builder $shipments) => $shipments->withoutGlobalScopes()->leftWarehouse());
            })
            ->when($search, function (Builder $q, string $search): void {
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('order_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_email', 'like', "%{$search}%");
                });
            });

        if ($this->warehouseAccess->isRestricted($user)) {
            $query->whereHas('items.product', function ($products) use ($user) {
                $this->warehouseAccess->scopeByLocation($products, $user, 'products.location_id');
            });
        }

        return $query;
    }

    /**
     * @throws AuthorizationException when none of the return's lines comes back to the user's warehouses
     */
    public function authorizeView(ReturnOrder $returnOrder, User $user): void
    {
        if (! $this->warehouseAccess->isRestricted($user)) {
            return;
        }

        $returnOrder->loadMissing('items.product');

        $this->warehouseAccess->authorizeAnyLocation(
            $user,
            $returnOrder->items->map(fn ($item) => $item->product?->location_id)->all(),
        );
    }

    /**
     * What the customer actually paid for each order line's goods, as a 2-dp
     * string keyed by order item id: the gross line (quantity x unit price)
     * less the line's own discount, less the line's share of the order-level
     * discount prorated by net line value. Tax and shipping are excluded,
     * matching how refunds have always been priced (goods only).
     *
     * @return array<int, string>
     */
    public function paidLineNets(Order $order): array
    {
        $order->loadMissing('items');

        $nets = [];
        foreach ($order->items->sortBy('id') as $item) {
            $gross = Money::of($item->subtotal);
            if (Money::compare($gross, '0') === 0) {
                // Rows written before subtotal was maintained.
                $gross = Money::multiply($item->unit_price, $item->quantity);
            }
            $nets[$item->id] = Money::max('0', Money::subtract($gross, $item->discount_amount ?? 0));
        }

        $merchandise = Money::add(...array_values($nets ?: ['0']));
        $lineDiscounts = Money::add(...$order->items->map(fn (OrderItem $i) => $i->discount_amount ?? 0)->all() ?: ['0']);
        $orderDiscount = Money::subtract($order->discount_amount ?? 0, $lineDiscounts);

        if (Money::compare($orderDiscount, '0') <= 0 || Money::compare($merchandise, '0') <= 0) {
            return $nets;
        }

        return $this->allocateAmount(Money::max('0', Money::subtract($merchandise, $orderDiscount)), $nets);
    }

    /**
     * Allocate a total by weights, retaining every cent in stable key order.
     * Cumulative rounded shares avoid independently creating or losing cents.
     *
     * @param  array<int, string>  $weights
     * @return array<int, string>
     */
    private function allocateAmount(string $amount, array $weights): array
    {
        ksort($weights, SORT_NUMERIC);
        $weightTotal = '0';
        foreach ($weights as $weight) {
            $weightTotal = bcadd($weightTotal, $weight, 8);
        }
        $cumulative = '0.00';
        $allocated = '0.00';
        $result = [];
        foreach ($weights as $key => $weight) {
            $cumulative = bcadd($cumulative, $weight, 8);
            $target = bccomp($weightTotal, '0', 8) === 0 ? '0.00'
                : Money::round(bcdiv(bcmul($amount, $cumulative, 8), $weightTotal, 8));
            $result[$key] = Money::subtract($target, $allocated);
            $allocated = $target;
        }

        return $result;
    }

    /**
     * Refund the cumulative entitlement less the amount already reserved for
     * this line. Using actual amounts also handles rejection of an earlier
     * return whose rounding cent differed from another return's.
     *
     * @param  array<int, string>  $paidNets  from paidLineNets()
     */
    public function refundFor(OrderItem $item, int $quantity, array $paidNets, int $alreadyReturned = 0, ?string $alreadyRefunded = null): string
    {
        $ordered = (int) $item->quantity;
        if ($quantity <= 0 || $ordered <= 0) {
            return '0.00';
        }

        $net = Money::max('0', $paidNets[$item->id] ?? Money::multiply($item->unit_price, $ordered));
        $priorQuantity = max(0, min($ordered, $alreadyReturned));
        $afterQuantity = min($ordered, $priorQuantity + $quantity);
        $entitlement = fn (int $units) => Money::round(bcdiv(bcmul($net, (string) $units, 8), (string) $ordered, 8));
        $reserved = Money::max('0', $alreadyRefunded ?? $entitlement($priorQuantity));

        return Money::max('0', Money::min(Money::subtract($entitlement($afterQuantity), $reserved), Money::subtract($net, $reserved)));
    }

    /**
     * Actual non-rejected refund amounts per line. New rows carry their exact
     * amount. Legacy headers are allocated proportionally across their lines
     * for this calculation only; no historical financial record is rewritten.
     *
     * @param  array<int, string>  $paidNets
     * @return array<int, string>
     */
    public function refundedLineAmounts(Order $order, array $paidNets): array
    {
        $order->loadMissing('items');
        $orderItems = $order->items->keyBy('id');
        $amounts = [];
        $returns = ReturnOrder::where('order_id', $order->id)->where('status', '!=', 'rejected')->with('items')->get();
        foreach ($returns as $return) {
            $known = '0.00';
            $legacyWeights = [];
            foreach ($return->items as $item) {
                if ($item->refund_amount !== null) {
                    $amount = Money::max('0', $item->refund_amount);
                    $amounts[$item->order_item_id] = Money::add($amounts[$item->order_item_id] ?? '0', $amount);
                    $known = Money::add($known, $amount);
                } elseif (($orderItem = $orderItems->get($item->order_item_id)) !== null) {
                    $weight = (int) $orderItem->quantity > 0
                        ? bcdiv(bcmul($paidNets[$orderItem->id] ?? '0', (string) $item->quantity, 8), (string) $orderItem->quantity, 8) : '0';
                    $legacyWeights[$item->order_item_id] = bcadd($legacyWeights[$item->order_item_id] ?? '0', $weight, 8);
                }
            }
            foreach ($this->allocateAmount(Money::max('0', Money::subtract($return->refund_amount, $known)), $legacyWeights) as $id => $amount) {
                $amounts[$id] = Money::add($amounts[$id] ?? '0', $amount);
            }
        }

        return $amounts;
    }

    /** The goods-only refund balance, counting existing header amounts exactly. */
    public function remainingRefundBalance(Order $order, ?array $paidNets = null): string
    {
        $paid = Money::add(...array_values(($paidNets ?? $this->paidLineNets($order)) ?: ['0']));
        $reserved = ReturnOrder::where('order_id', $order->id)->where('status', '!=', 'rejected')
            ->where('refund_amount', '>', 0)->sum('refund_amount');

        return Money::max('0', Money::subtract($paid, $reserved));
    }

    /**
     * Quantities already returned per order item (excluding rejected returns).
     *
     * @return Collection<int, int|string>
     *
     * @api
     */
    public function returnedQuantities(Order $order): Collection
    {
        return ReturnOrderItem::whereHas('returnOrder', function ($q) use ($order) {
            $q->where('order_id', $order->id)
                ->whereNotIn('status', ['rejected']);
        })->selectRaw('order_item_id, SUM(quantity) as total_returned')
            ->groupBy('order_item_id')
            ->pluck('total_returned', 'order_item_id');
    }

    /**
     * Units that physically left the warehouse, capped at the ordered amount.
     * Old shipped/delivered orders with no shipment history retain their
     * original full-order return allowance.
     *
     * @return Collection<int, int>
     */
    public function shippedQuantities(Order $order): Collection
    {
        $order->loadMissing('items');
        $hasShipmentHistory = Shipment::withoutGlobalScopes()->where('order_id', $order->id)->exists();
        if (! $hasShipmentHistory && in_array($order->status, [OrderStatus::SHIPPED, OrderStatus::DELIVERED], true)) {
            return $order->items->mapWithKeys(fn (OrderItem $item) => [$item->id => (int) $item->quantity]);
        }

        $shipped = ShipmentItem::query()
            ->whereHas('shipment', fn (Builder $query) => $query->withoutGlobalScopes()->where('order_id', $order->id)->leftWarehouse())
            ->selectRaw('order_item_id, SUM(quantity) as shipped_quantity')
            ->groupBy('order_item_id')
            ->pluck('shipped_quantity', 'order_item_id');

        return $order->items->mapWithKeys(fn (OrderItem $item) => [
            $item->id => min((int) $item->quantity, (int) $shipped->get($item->id, 0)),
        ]);
    }

    /**
     * Create a pending return against an order.
     *
     * @param  array{order_id: int, type: string, reason: string, notes?: string|null, items: array<int, array{order_item_id: int, quantity: int, condition: string, restock: bool}>}  $data
     * @param  User|null  $actor  The staff user raising it, whose warehouse access is enforced;
     *                            null for a customer's own request from the customer portal,
     *                            which staff warehouse restrictions do not apply to (staff
     *                            still need access to approve and receive it).
     *
     * @throws ValidationException when a line exceeds its returnable quantity
     *
     * @api
     */
    public function create(int $organizationId, ?User $actor, array $data): ReturnOrder
    {
        // Verify the order belongs to this organization
        $order = Order::forOrganization($organizationId)->findOrFail($data['order_id']);
        $order->load('items.product');

        // Every returned line restocks into its product's primary location,
        // so a restricted user may only raise returns into their warehouses.
        foreach ($data['items'] as $item) {
            $orderItem = $order->items->firstWhere('id', $item['order_item_id']);
            if ($orderItem !== null && $actor !== null) {
                $this->warehouseAccess->authorizeLocation($actor, $orderItem->product?->location_id);
            }
        }

        // Retry on a return_number unique collision: two returns for
        // different orders in the same org/second read the same MAX and
        // would otherwise 500 (the parent-order lock only serialises returns
        // of the SAME order).
        return SequenceNumberRetry::create(fn () => DB::transaction(function () use ($data, $organizationId, $order) {
            // Lock the parent Order row, not the aggregate. The previous
            // implementation applied lockForUpdate() to a SELECT with
            // GROUP BY — Postgres rejects locking aggregated rows
            // outright, SQLite silently ignores FOR UPDATE entirely,
            // and MySQL locks the visible aggregated rows rather than
            // the underlying return_order_items, so concurrent submissions
            // could still slip past the "already returned" check on
            // some engines. Locking the parent Order forces sequential
            // return submissions against the same order on every
            // supported driver.
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();

            // Only goods that left can come back. A return against an order
            // that never shipped (its stock is still held and goes back on
            // cancel) or one already cancelled (its stock already went back)
            // would restock the same units twice.
            $order = $lockedOrder ?? $order;
            $order->load('items.product');
            $this->assertReturnable($order);

            $shippedQuantities = $this->shippedQuantities($order);
            $returnedQuantities = $this->returnedQuantities($order);

            // Sum what this request asks for per order line, so repeating a
            // line cannot slip each copy past the cap on its own.
            $requested = [];
            $errors = [];
            foreach ($data['items'] as $index => $item) {
                $orderItem = $order->items->firstWhere('id', $item['order_item_id']);
                if (! $orderItem) {
                    $errors["items.{$index}.order_item_id"] = 'Order item not found.';

                    continue;
                }

                if ((int) $item['quantity'] <= 0) {
                    $errors["items.{$index}.quantity"] = 'Return quantity must be positive.';

                    continue;
                }

                $requested[$orderItem->id] = ($requested[$orderItem->id] ?? 0) + (int) $item['quantity'];

                $alreadyReturned = (int) $returnedQuantities->get($item['order_item_id'], 0);
                $shipped = $shippedQuantities->get($orderItem->id, 0);
                $maxReturnable = max(0, $shipped - $alreadyReturned);

                if ($requested[$orderItem->id] > $maxReturnable) {
                    $errors["items.{$index}.quantity"] = "Cannot return more than {$maxReturnable} units (shipped: {$shipped}, already returned: {$alreadyReturned}).";
                }
            }

            if (! empty($errors)) {
                throw ValidationException::withMessages($errors);
            }

            // Refund what the customer paid for the goods: net of the line
            // discount and the line's share of the order-level discount.
            $paidNets = $this->paidLineNets($order);
            $refundedAmounts = $this->refundedLineAmounts($order, $paidNets);
            $refundAmounts = [];
            foreach ($data['items'] as $index => $item) {
                $orderItem = $order->items->firstWhere('id', $item['order_item_id']);
                $priorQuantity = (int) $returnedQuantities->get($orderItem->id, 0);
                $priorRefund = $refundedAmounts[$orderItem->id] ?? '0.00';
                $amount = $this->refundFor($orderItem, (int) $item['quantity'], $paidNets, $priorQuantity, $priorRefund);
                $refundAmounts[$index] = $amount;
                $returnedQuantities->put($orderItem->id, $priorQuantity + (int) $item['quantity']);
                $refundedAmounts[$orderItem->id] = Money::add($priorRefund, $amount);
            }

            $refundAmount = Money::add(...array_values($refundAmounts ?: ['0']));
            $balance = $this->remainingRefundBalance($order, $paidNets);
            if (Money::compare($refundAmount, $balance) > 0) {
                $refundAmounts = $this->allocateAmount($balance, $refundAmounts);
                $refundAmount = $balance;
            }

            $returnOrder = ReturnOrder::create([
                'organization_id' => $organizationId,
                'order_id' => $order->id,
                'return_number' => ReturnOrder::generateReturnNumber($organizationId),
                'type' => $data['type'],
                'status' => 'pending',
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'refund_amount' => $refundAmount,
            ]);

            foreach ($data['items'] as $index => $item) {
                // Derive the product from the order line, NOT the client
                // payload. order_item_id is what caps the return quantity and
                // computes the refund; taking product_id from the request
                // instead let a caller point the restock at a different
                // product than the one actually on that line, inflating an
                // arbitrary product's on-hand on receive.
                $orderItem = $order->items->firstWhere('id', $item['order_item_id']);

                ReturnOrderItem::create([
                    'return_order_id' => $returnOrder->id,
                    'order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'quantity' => $item['quantity'],
                    'refund_amount' => $refundAmounts[$index],
                    'condition' => $item['condition'],
                    'restock' => $item['restock'],
                ]);
            }

            return $returnOrder;
        }));
    }

    /**
     * Approve a pending return.
     *
     * @api
     */
    public function approve(ReturnOrder $returnOrder, User $actor): ReturnOrder
    {
        $this->authorizeView($returnOrder, $actor);

        return DB::transaction(function () use ($returnOrder, $actor) {
            $locked = ReturnOrder::whereKey($returnOrder->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new InvalidStateException('Only pending returns can be approved.', 'invalid_status');
            }

            $locked->update([
                'status' => 'approved',
                'processed_by' => $actor->id,
            ]);
            $returnOrder->setRawAttributes($locked->getAttributes(), true);

            return $returnOrder;
        });
    }

    /**
     * Change the restock flag and/or condition of lines on a pending or
     * approved return, before it is received. Receiving is what books the
     * stock, so this is the last point at which the decision can change.
     *
     * Every line being changed must restock into one of the actor's
     * warehouses (the same rule receive() applies). Lines not on this return
     * are a validation error keyed `items.{index}.id`. The change is written
     * to the activity log with each line's old and new values.
     *
     * @param  array<int, array{id: int|string, restock?: bool|int|string|null, condition?: string|null}>  $lines
     *
     * @throws InvalidStateException when the return is no longer pending or approved
     * @throws ValidationException when a line is not on this return
     * @throws AuthorizationException when a changed line restocks outside the actor's warehouses
     *
     * @api
     */
    public function updateLines(ReturnOrder $returnOrder, User $actor, array $lines): ReturnOrder
    {
        $this->authorizeView($returnOrder, $actor);

        return DB::transaction(function () use ($returnOrder, $actor, $lines) {
            // Lock and re-check the status so a concurrent receive cannot
            // restock with half-applied line changes.
            $locked = ReturnOrder::whereKey($returnOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['pending', 'approved'], true)) {
                throw new InvalidStateException('Lines can only be changed on pending or approved returns.', 'invalid_status');
            }

            $locked->load('items.product');

            $errors = [];
            foreach ($lines as $index => $line) {
                if (! $locked->items->contains('id', (int) $line['id'])) {
                    $errors["items.{$index}.id"] = 'This line is not on the return.';
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $changes = [];
            foreach ($lines as $line) {
                $item = $locked->items->firstWhere('id', (int) $line['id']);

                $this->warehouseAccess->authorizeLocation($actor, $item->product?->location_id);

                $diff = [];
                if (array_key_exists('restock', $line) && $line['restock'] !== null) {
                    $restock = filter_var($line['restock'], FILTER_VALIDATE_BOOLEAN);
                    if ($restock !== (bool) $item->restock) {
                        $diff['restock'] = ['old' => (bool) $item->restock, 'new' => $restock];
                        $item->restock = $restock;
                    }
                }
                if (array_key_exists('condition', $line) && $line['condition'] !== null && $line['condition'] !== $item->condition) {
                    $diff['condition'] = ['old' => $item->condition, 'new' => $line['condition']];
                    $item->condition = $line['condition'];
                }

                if ($diff !== []) {
                    $item->save();
                    $changes[$item->id] = $diff;
                }
            }

            if ($changes !== []) {
                ActivityLog::create([
                    'organization_id' => $locked->organization_id,
                    'user_id' => $actor->id,
                    'category' => ActivityLog::CATEGORY_AUDIT,
                    'subject_type' => ReturnOrder::class,
                    'subject_id' => $locked->id,
                    'action' => 'return.lines_updated',
                    'description' => "Changed restock or condition on return {$locked->return_number}",
                    'properties' => ['lines' => $changes],
                    'ip_address' => request()?->ip(),
                    'user_agent' => request() ? substr((string) request()->userAgent(), 0, 1000) : null,
                ]);
            }

            return $locked;
        });
    }

    /**
     * Receive an approved return, restocking every line marked for restock.
     *
     * @param  User|null  $actor  Who receives it: their warehouse access is enforced and the
     *                            restock ledger rows are attributed to them. Callers outside a
     *                            request (a queued job, a command, a plugin's sync) pass one
     *                            explicitly. Without one the signed-in user is used; with
     *                            nobody signed in either, the return is received as a system
     *                            action (no warehouse restriction applies) and the ledger
     *                            records whoever approved it, else the order's creator, else
     *                            the organization's first user.
     *
     * @api
     */
    public function receive(ReturnOrder $returnOrder, ?User $actor = null): ReturnOrder
    {
        $signedIn = auth()->user();
        $actor ??= $signedIn instanceof User ? $signedIn : null;

        if ($actor !== null) {
            $this->authorizeView($returnOrder, $actor);

            // Receiving books stock into each restocked line's location, so every
            // one of them must be in the actor's warehouses.
            $returnOrder->loadMissing('items.product');
            foreach ($returnOrder->items as $item) {
                if ($item->restock) {
                    $this->warehouseAccess->authorizeLocation($actor, $item->product?->location_id);
                }
            }
        }

        if ($returnOrder->status !== 'approved') {
            throw new InvalidStateException('Only approved returns can be received.', 'invalid_status');
        }

        return DB::transaction(function () use ($returnOrder, $actor) {
            // Re-read the return under a row lock and re-assert its status
            // inside the transaction. The pre-transaction guard above runs
            // against the unlocked route-model instance, so a concurrent
            // double-submit could pass that check twice and double-restock.
            // Locking + re-checking here forces the second request to wait,
            // observe status === 'received', and bail without restocking.
            $locked = ReturnOrder::whereKey($returnOrder->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved') {
                throw new InvalidStateException('Only approved returns can be received.', 'invalid_status');
            }

            $this->assertReceivable($locked);

            // stock_adjustments.user_id is required, so the restock must
            // never depend on a session being present.
            $ledgerActor = $actor ?? $this->systemActor($locked);

            foreach ($locked->items as $item) {
                // A line sold as a variant decremented the variant, so the
                // return credits the variant back (lines raised before the
                // column existed fall back to their order line's variant).
                // Its primary bin receives the returned units with the total.
                $variant = $item->variant ?? $item->orderItem?->variant;

                if ($item->restock && $item->product && $variant !== null) {
                    StockAdjustment::adjustVariant(
                        $variant,
                        $item->quantity,
                        'return',
                        'Return restock',
                        "Restocked from return {$locked->return_number}",
                        $locked,
                        actor: $ledgerActor,
                    );

                    if ($item->orderItem !== null) {
                        $this->trackedStock->releaseForOrderItem($item->orderItem, (int) $item->quantity);
                    }
                } elseif ($item->restock && $item->product) {
                    StockAdjustment::adjust(
                        $item->product,
                        $item->quantity,
                        'return',
                        'Return restock',
                        "Restocked from return {$locked->return_number}",
                        $locked,
                        actor: $ledgerActor,
                        // Book the returned units into the product's location
                        // bin so the per-location breakdown rises with the
                        // total instead of drifting into "unassigned" — the
                        // same treatment order-cancel and PO-receipt use.
                        locationId: $item->product->location_id,
                    );

                    // Return the specific serials/batches this line consumed
                    // back to available, up to the returned quantity, so
                    // COUNT(available) rises with the total instead of lagging
                    // until reconcile. Runs after adjust() locked the product,
                    // preserving the product-before-tracked lock order.
                    if ($item->order_item_id !== null) {
                        $orderItem = OrderItem::find($item->order_item_id);
                        if ($orderItem !== null) {
                            $this->trackedStock->releaseForOrderItem($orderItem, (int) $item->quantity);
                        }
                    }
                }
            }

            $locked->update([
                'status' => 'received',
                'processed_by' => $ledgerActor?->id ?? $locked->processed_by,
            ]);

            return $locked;
        });
    }

    /**
     * Who a return received with no actor and nobody signed in is attributed
     * to: whoever approved it, else the order's creator, else the
     * organization's first user.
     */
    private function systemActor(ReturnOrder $locked): ?User
    {
        $createdBy = Order::withoutGlobalScopes()->whereKey($locked->order_id)->value('created_by');

        return ($locked->processed_by !== null ? User::withoutGlobalScopes()->find($locked->processed_by) : null)
            ?? ($createdBy !== null ? User::withoutGlobalScopes()->find($createdBy) : null)
            ?? User::withoutGlobalScopes()->where('organization_id', $locked->organization_id)->orderBy('id')->first();
    }

    /**
     * Refuse a return against an order whose goods have not left: one that
     * is cancelled, or one neither marked shipped/delivered nor with a
     * shipment that has left the warehouse.
     *
     * @throws ValidationException
     */
    private function assertReturnable(Order $order): void
    {
        if ($order->status === OrderStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'order_id' => 'Returns cannot be raised against a cancelled order.',
            ]);
        }

        if (in_array($order->status, [OrderStatus::SHIPPED, OrderStatus::DELIVERED], true)) {
            return;
        }

        $anyLeft = Shipment::withoutGlobalScopes()
            ->where('order_id', $order->getKey())
            ->leftWarehouse()
            ->exists();

        if (! $anyLeft) {
            throw ValidationException::withMessages([
                'order_id' => 'Returns can only be raised once the order has shipped.',
            ]);
        }
    }

    /**
     * Re-check a return under its lock before it restocks anything: its
     * order must not have been cancelled since (cancelling gave the stock
     * back already), and no order line may come back more times than it was
     * shipped across this and earlier received returns. The parent order
     * lock serializes receiving separate returns against the same order.
     *
     * @throws InvalidStateException when the order has been cancelled
     * @throws ValidationException when a line would exceed its shipped quantity
     */
    private function assertReceivable(ReturnOrder $locked): void
    {
        $order = Order::withoutGlobalScopes()->with('items')->lockForUpdate()->find($locked->order_id);

        if ($order === null || $order->status === OrderStatus::CANCELLED) {
            throw new InvalidStateException('This return\'s order has been cancelled, so it cannot be received.', 'invalid_status');
        }

        // Load lines only after the parent lock: MySQL's consistent-read
        // snapshot must be established after any earlier receiver commits.
        $locked->load('items.product', 'items.variant', 'items.orderItem.variant');

        $alreadyReceived = ReturnOrderItem::query()
            ->whereHas('returnOrder', fn ($query) => $query->withoutGlobalScopes()
                ->where('order_id', $order->getKey())
                ->whereKeyNot($locked->getKey())
                ->whereIn('status', ['received', 'completed']))
            ->selectRaw('order_item_id, SUM(quantity) as total_returned')
            ->groupBy('order_item_id')
            ->pluck('total_returned', 'order_item_id');

        $shippedQuantities = $this->shippedQuantities($order);
        $incoming = [];
        $errors = [];
        foreach ($locked->items->values() as $index => $item) {
            $orderItem = $order->items->firstWhere('id', $item->order_item_id);
            if ($orderItem === null) {
                continue;
            }

            $incoming[$orderItem->id] = ($incoming[$orderItem->id] ?? 0) + (int) $item->quantity;
            $received = (int) ($alreadyReceived[$orderItem->id] ?? 0);

            $shipped = $shippedQuantities->get($orderItem->id, 0);
            if ($received + $incoming[$orderItem->id] > $shipped) {
                $errors["items.{$index}.quantity"] = "Cannot receive more than {$shipped} shipped units of {$orderItem->product_name} against this order ({$received} already returned).";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Complete a received return.
     *
     * @api
     */
    public function complete(ReturnOrder $returnOrder, User $actor): ReturnOrder
    {
        $this->authorizeView($returnOrder, $actor);

        return DB::transaction(function () use ($returnOrder, $actor) {
            $locked = ReturnOrder::whereKey($returnOrder->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'received') {
                throw new InvalidStateException('Only received returns can be completed.', 'invalid_status');
            }

            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
                'processed_by' => $actor->id,
            ]);
            $returnOrder->setRawAttributes($locked->getAttributes(), true);

            return $returnOrder;
        });
    }

    /**
     * Reject a pending return, appending the reason to its notes.
     *
     * @api
     */
    public function reject(ReturnOrder $returnOrder, User $actor, ?string $reason = null): ReturnOrder
    {
        $this->authorizeView($returnOrder, $actor);

        return DB::transaction(function () use ($returnOrder, $actor, $reason) {
            $locked = ReturnOrder::whereKey($returnOrder->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new InvalidStateException('Only pending returns can be rejected.', 'invalid_status');
            }

            $locked->update([
                'status' => 'rejected',
                'processed_by' => $actor->id,
                'notes' => $locked->notes
                    ? $locked->notes."\n\nRejection reason: ".($reason ?? 'No reason provided')
                    : 'Rejection reason: '.($reason ?? 'No reason provided'),
            ]);
            $returnOrder->setRawAttributes($locked->getAttributes(), true);

            return $returnOrder;
        });
    }
}
