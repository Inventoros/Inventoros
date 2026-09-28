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
     * Quantities already returned per order item (excluding rejected returns).
     *
     * @return Collection<int, int|string>
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
     * Create a pending return against an order.
     *
     * @param  array{order_id: int, type: string, reason: string, notes?: string|null, items: array<int, array{order_item_id: int, quantity: int, condition: string, restock: bool}>}  $data
     *
     * @param  User|null  $actor  The staff user raising it, whose warehouse access is enforced;
     *                           null for a customer's own request from the customer portal,
     *                           which staff warehouse restrictions do not apply to (staff
     *                           still need access to approve and receive it).
     *
     * @throws ValidationException when a line exceeds its returnable quantity
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
            $this->assertReturnable($lockedOrder ?? $order);

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

                $requested[$orderItem->id] = ($requested[$orderItem->id] ?? 0) + (int) $item['quantity'];

                $alreadyReturned = (int) $returnedQuantities->get($item['order_item_id'], 0);
                $maxReturnable = $orderItem->quantity - $alreadyReturned;

                if ($requested[$orderItem->id] > $maxReturnable) {
                    $errors["items.{$index}.quantity"] = "Cannot return more than {$maxReturnable} units (ordered: {$orderItem->quantity}, already returned: {$alreadyReturned}).";
                }
            }

            if (! empty($errors)) {
                throw ValidationException::withMessages($errors);
            }

            // Calculate refund amount
            $refundAmount = '0';
            foreach ($data['items'] as $item) {
                $orderItem = $order->items->firstWhere('id', $item['order_item_id']);
                $refundAmount = Money::add($refundAmount, Money::multiply($orderItem->unit_price, $item['quantity']));
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

            foreach ($data['items'] as $item) {
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
                    'condition' => $item['condition'],
                    'restock' => $item['restock'],
                ]);
            }

            return $returnOrder;
        }));
    }

    /**
     * Approve a pending return.
     */
    public function approve(ReturnOrder $returnOrder, User $actor): ReturnOrder
    {
        $this->authorizeView($returnOrder, $actor);

        if ($returnOrder->status !== 'pending') {
            throw new InvalidStateException('Only pending returns can be approved.', 'invalid_status');
        }

        $returnOrder->update([
            'status' => 'approved',
            'processed_by' => $actor->id,
        ]);

        return $returnOrder;
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
     */
    public function receive(ReturnOrder $returnOrder, User $actor): ReturnOrder
    {
        $this->authorizeView($returnOrder, $actor);

        // Receiving books stock into each restocked line's location, so every
        // one of them must be in the actor's warehouses.
        $returnOrder->loadMissing('items.product');
        foreach ($returnOrder->items as $item) {
            if ($item->restock) {
                $this->warehouseAccess->authorizeLocation($actor, $item->product?->location_id);
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

            $locked->load('items.product');

            $this->assertReceivable($locked);

            foreach ($locked->items as $item) {
                if ($item->restock && $item->product) {
                    StockAdjustment::adjust(
                        $item->product,
                        $item->quantity,
                        'return',
                        'Return restock',
                        "Restocked from return {$locked->return_number}",
                        $locked,
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
                'processed_by' => $actor->id,
            ]);

            return $locked;
        });
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
     * sold across this and earlier received returns.
     *
     * @throws InvalidStateException when the order has been cancelled
     * @throws ValidationException when a line would exceed its sold quantity
     */
    private function assertReceivable(ReturnOrder $locked): void
    {
        $order = Order::withoutGlobalScopes()->with('items')->find($locked->order_id);

        if ($order === null || $order->status === OrderStatus::CANCELLED) {
            throw new InvalidStateException('This return\'s order has been cancelled, so it cannot be received.', 'invalid_status');
        }

        $alreadyReceived = ReturnOrderItem::query()
            ->whereHas('returnOrder', fn ($query) => $query->withoutGlobalScopes()
                ->where('order_id', $order->getKey())
                ->whereKeyNot($locked->getKey())
                ->whereIn('status', ['received', 'completed']))
            ->selectRaw('order_item_id, SUM(quantity) as total_returned')
            ->groupBy('order_item_id')
            ->pluck('total_returned', 'order_item_id');

        $incoming = [];
        $errors = [];
        foreach ($locked->items->values() as $index => $item) {
            $orderItem = $order->items->firstWhere('id', $item->order_item_id);
            if ($orderItem === null) {
                continue;
            }

            $incoming[$orderItem->id] = ($incoming[$orderItem->id] ?? 0) + (int) $item->quantity;
            $received = (int) ($alreadyReceived[$orderItem->id] ?? 0);

            if ($received + $incoming[$orderItem->id] > (int) $orderItem->quantity) {
                $errors["items.{$index}.quantity"] = "Cannot receive more than {$orderItem->quantity} units of {$orderItem->product_name} against this order ({$received} already returned).";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Complete a received return.
     */
    public function complete(ReturnOrder $returnOrder, User $actor): ReturnOrder
    {
        $this->authorizeView($returnOrder, $actor);

        if ($returnOrder->status !== 'received') {
            throw new InvalidStateException('Only received returns can be completed.', 'invalid_status');
        }

        $returnOrder->update([
            'status' => 'completed',
            'completed_at' => now(),
            'processed_by' => $actor->id,
        ]);

        return $returnOrder;
    }

    /**
     * Reject a pending return, appending the reason to its notes.
     */
    public function reject(ReturnOrder $returnOrder, User $actor, ?string $reason = null): ReturnOrder
    {
        $this->authorizeView($returnOrder, $actor);

        if ($returnOrder->status !== 'pending') {
            throw new InvalidStateException('Only pending returns can be rejected.', 'invalid_status');
        }

        $returnOrder->update([
            'status' => 'rejected',
            'processed_by' => $actor->id,
            'notes' => $returnOrder->notes
                ? $returnOrder->notes."\n\nRejection reason: ".($reason ?? 'No reason provided')
                : 'Rejection reason: '.($reason ?? 'No reason provided'),
        ]);

        return $returnOrder;
    }
}
