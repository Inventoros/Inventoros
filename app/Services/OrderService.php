<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Exceptions\InvalidStateException;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\OrderItemKitComponent;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\ReturnOrder;
use App\Models\Order\ReturnOrderItem;
use App\Models\Shipping\Shipment;
use App\Models\User;
use App\Services\Inventory\KitSaleService;
use App\Support\ApprovalSettings;
use App\Support\BackorderSettings;
use App\Support\LineTax;
use App\Support\Money;
use App\Support\OrderApprovalGate;
use App\Support\SequenceNumberRetry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single home for sales-order creation.
 *
 * The Inertia, REST, GraphQL, and MCP surfaces each used to hand-roll the same
 * lock-validate-decrement transaction, and they had already drifted (different
 * stock checks, different ledger fidelity, auth() vs payload for the actor).
 * This service owns the invariant so every surface creates orders identically:
 *
 *  - order_number is generated INSIDE the transaction and the whole thing is
 *    wrapped in SequenceNumberRetry, so a unique-constraint collision on a
 *    concurrent insert is retried rather than surfaced.
 *  - every referenced product is batch-locked with SELECT ... FOR UPDATE before
 *    any availability check, closing the read-modify-write race on stock.
 *  - availability is validated across ALL line items first (multiple lines of
 *    the same product accumulate against one running balance).
 *  - a StockAdjustment ledger row is written per line item with faithful
 *    quantity_before / quantity_after threading, and stock is decremented once
 *    per unique product.
 *
 * The caller resolves the warehouse (session/default fallback lives in the web
 * layer) and passes the acting User explicitly — the service never reaches for
 * auth(), so it behaves identically under web, API, queue, and console.
 */
final class OrderService
{
    /**
     * Plugin filter applied to every computed order total (see applyTotals()).
     */
    public const TOTAL_FILTER = 'order_total_calculation';

    /**
     * Money columns the server always computes. Anything a caller sends for
     * them is discarded, so a client can never set its own total.
     */
    private const COMPUTED_COLUMNS = [
        'subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'tax', 'shipping', 'total', 'amount_paid', 'payment_status',
    ];

    /**
     * Create an order with its line items and stock movements.
     *
     * @param  array<string, mixed>  $data  Validated order payload. Must contain
     *                                      an `items` array of
     *                                      {product_id, quantity, unit_price};
     *                                      may contain customer_*, status,
     *                                      order_date, warehouse_id, tax,
     *                                      shipping, notes, approval_status.
     * @param  User  $creator  The acting user; sets organization_id, created_by,
     *                         and the ledger actor.
     * @param  string  $source  Order source channel (manual, ebay, …).
     * @param  bool  $adjustStock  False records the order and its lines without
     *                             touching inventory: no availability check, no
     *                             stock decrement, no bin consumption, no ledger
     *                             rows, no serial allocation. Used by the
     *                             historical order import, where the goods left
     *                             long ago and current stock already reflects it.
     * @param  bool  $announce  False skips the `order_created` action, so no
     *                          order.created webhook is queued and no plugin
     *                          listener runs. Used by the order import for
     *                          historical orders (and on request), which are
     *                          records of past sales, not new ones.
     *
     * Availability is checked against available stock: on hand less the
     * active stock reservations (StockReservationService). An order that
     * fulfils a reservation passes `reservation_owner` and
     * `reservation_reference` in $data: it is checked against available plus
     * that reservation's holds, draws the held bins, serials and batch units,
     * and consumes the holds in the same transaction (exactly once; a
     * reservation that is no longer active is refused). Ignored when
     * $adjustStock is false.
     *
     * @throws \Exception When a product is missing or stock is insufficient.
     * @throws QueryException On unrecoverable DB errors.
     *
     * @api
     */
    public function create(array $data, User $creator, string $source = 'manual', bool $adjustStock = true, bool $announce = true): Order
    {
        // An order recorded as already cancelled never takes stock: cancel()
        // and deletion both skip a cancelled order, so stock taken here could
        // never come back. The order import does the same.
        $initialStatus = $data['status'] ?? null;
        if (($initialStatus instanceof OrderStatus ? $initialStatus : OrderStatus::tryFrom((string) $initialStatus)) === OrderStatus::CANCELLED) {
            $adjustStock = false;
        }

        if ($adjustStock && isset($data['warehouse_id'])) {
            app(WarehouseAccessService::class)->authorizeWarehouse($creator, (int) $data['warehouse_id']);
        }
        $data['organization_id'] = $creator->organization_id;
        $data['created_by'] = $creator->id;
        $data['source'] = $source;
        // Orders wait for approval only when the organization requires it.
        $data['approval_status'] ??= ApprovalSettings::forOrganization((int) $creator->organization_id)
            ->initialOrderApprovalStatus()->value;
        $this->assertCreatableWhileAwaitingApproval($data);
        // Orders are in the organization's currency unless the caller names one.
        $data['currency'] = $this->currencyCode($data['currency'] ?? null, (int) $creator->organization_id);
        [$reservationOwner, $reservationReference] = $this->reservationFrom($data);
        unset($data['reservation_owner'], $data['reservation_reference']);

        $order = SequenceNumberRetry::create(fn () => DB::transaction(function () use ($data, $creator, $adjustStock, $reservationOwner, $reservationReference) {
            $orgId = $data['organization_id'];
            $data['order_number'] = Order::generateOrderNumber($orgId);
            // Short lines are accepted as backorders only when the org's
            // master switch is on; per-product opt-in is checked per line.
            $backorderSettings = BackorderSettings::forOrganization((int) $orgId);

            // Batch-lock every referenced product (and variant) in single
            // SELECT ... FOR UPDATE queries so concurrent orders can't race the
            // read-modify-write on stock. A kit line draws on its components,
            // so they are locked in the same sorted batch as the line products.
            $productIds = array_unique(array_column($data['items'], 'product_id'));
            $lockIds = $adjustStock
                ? array_unique(array_merge($productIds, $this->kitComponentIds($productIds, (int) $orgId)))
                : $productIds;
            sort($lockIds);
            $products = Product::whereIn('id', $lockIds)
                ->where('organization_id', $orgId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $variantIds = array_values(array_unique(array_filter(
                array_map(fn ($item) => $item['product_variant_id'] ?? null, $data['items'])
            )));
            $variants = $variantIds === []
                ? collect()
                : ProductVariant::whereIn('id', $variantIds)
                    ->where('organization_id', $orgId)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

            // Resolve each line's stock draws once: a specific variant when one
            // is chosen (validating ownership), otherwise the product itself,
            // or, for a kit, each of its components (see resolveLineDraws).
            // A variant-tracked product requires a variant on every new line.
            $lines = [];
            foreach (array_values($data['items']) as $index => $item) {
                $pid = $item['product_id'];
                if (! $products->has($pid)) {
                    throw new \Exception("Product not found: {$pid}");
                }
                $product = $products[$pid];
                if ($adjustStock) {
                    $this->authorizeTrackedStock($product, $creator);
                }
                $variantId = $item['product_variant_id'] ?? null;

                if ($variantId !== null) {
                    $variant = $variants->get($variantId);
                    if (! $variant || $variant->product_id !== $product->id) {
                        throw new InvalidOrderItemException(
                            "Variant {$variantId} does not belong to product {$product->name}."
                        );
                    }
                } else {
                    $variant = null;
                }

                // A kit recorded without touching stock (a historical import,
                // an order created cancelled) takes nothing, so its BOM is not
                // checked; the line is recorded as sold.
                $isKit = $product->isKit();
                $draws = $isKit && ! $adjustStock ? [] : $this->resolveLineDraws($product, $variant);

                if ($isKit) {
                    // Draw on the locked component rows. A component missing
                    // from the batch (the BOM changed since it was read) is
                    // locked now, before any balance is read.
                    $draws = $this->lockDrawTargets($draws, $products, (int) $orgId);
                    $target = $product;
                    $key = null;
                } else {
                    ['target' => $target, 'key' => $key] = $draws[0];
                }

                $lines[] = compact('item', 'product', 'variant', 'target', 'key', 'index', 'draws', 'isKit')
                    + ['qty' => (int) $item['quantity'], 'fulfillQty' => (int) $item['quantity'], 'backorderedQty' => 0];
            }

            // An order fulfilling a reservation consumes its holds first, so
            // the checks below count it against available plus those holds.
            // Rolled back with the order if anything below fails.
            $reservations = app(StockReservationService::class);
            $claimed = $adjustStock && $reservationOwner !== null
                ? $reservations->claim((int) $orgId, $reservationOwner, (string) $reservationReference)
                : null;
            foreach ($claimed ?? [] as $hold) {
                if ($hold->location_id !== null) {
                    app(WarehouseAccessService::class)->authorizeLocation($creator, (int) $hold->location_id);
                }
            }

            // Validate availability across ALL lines first, on one running
            // balance per stock target (see drawFromBalances). A plain line
            // short of its balance is split: the units the balance covers are
            // fulfilled, and the remainder is backordered when the org switch
            // is on and the product is opted in, otherwise refused exactly as
            // before. A short kit line is always refused.
            $running = [];
            $available = [];
            foreach ($adjustStock ? array_keys($lines) : [] as $i) {
                $line = $lines[$i];
                $fulfillQty = $this->drawFromBalances($line, $running, $available, $reservations);
                $key = $line['key'];
                $backorderedQty = $line['qty'] - $fulfillQty;

                if ($backorderedQty > 0 && ! $backorderSettings->allowsBackorderFor($line['product'])) {
                    throw new InsufficientStockException(
                        'Insufficient stock for '.$this->lineLabel($line)
                        .'. Available: '.max(0, $available[$key]).", Requested: {$line['qty']}"
                    );
                }

                $lines[$i]['fulfillQty'] = $fulfillQty;
                $lines[$i]['backorderedQty'] = $backorderedQty;
            }

            // Build order-item rows + stock-adjustment rows. quantity_before and
            // quantity_after thread the running stock so the ledger is faithful
            // when the order touches the same target twice.
            $orderItemRows = [];
            $now = now();
            $adjustmentRows = [];
            $perTargetQty = [];
            $targets = [];
            $threadStock = [];

            foreach ($lines as $line) {
                $item = $line['item'];
                $product = $line['product'];
                $variant = $line['variant'];
                $qty = $line['qty'];
                $fulfillQty = $line['fulfillQty'];
                $key = $line['key'];
                if (! $line['isKit']) {
                    $targets[$key] = $line['target'];
                }

                // unit_price is optional: callers may omit it and get the
                // product's price in the order's currency (see unitPriceFor).
                $unitPrice = $this->unitPriceFor($item, $product, $variant, $data['currency'], $line['index']);
                // Price the whole line (the customer is charged for every unit,
                // including backordered ones they will receive once it arrives),
                // and validate its discount before anything is written so a bad
                // discount rejects the whole order cleanly.
                $orderItemRows[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    'sku' => $variant?->sku ?? $product->sku,
                    'quantity' => $qty,
                    'quantity_backordered' => $line['backorderedQty'],
                    'unit_price' => $unitPrice,
                    // Cost at the time of sale, for margin and turnover reports.
                    'unit_cost' => OrderItem::costAtSale($product, $variant),
                ] + $this->priceLine($unitPrice, $qty, $item, $line['index']);

                // Only the fulfilled portion leaves stock; a backordered
                // remainder writes no ledger row and draws no bins until it is
                // reconciled when stock arrives. A kit line moves no stock of
                // its own: its components are taken after the insert below.
                if ($fulfillQty > 0 && ! $line['isKit']) {
                    $perTargetQty[$key] = ($perTargetQty[$key] ?? 0) + $fulfillQty;
                    $beforeForEntry = $threadStock[$key] ?? (int) $line['target']->stock;
                    $afterForEntry = $beforeForEntry - $fulfillQty;
                    $threadStock[$key] = $afterForEntry;

                    $adjustmentRows[] = [
                        'organization_id' => $orgId,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'user_id' => $creator->id,
                        'type' => 'order_fulfillment',
                        'quantity_before' => $beforeForEntry,
                        'quantity_after' => $afterForEntry,
                        'adjustment_quantity' => -$fulfillQty,
                        'reason' => null,  // set after order_number known
                        'notes' => null,
                        'reference_type' => Order::class,
                        'reference_id' => null,  // set after $order is created
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // Order tax = the sum of the line taxes plus any order-level tax
            // (see computeTotals() for how rated lines are taxed and why an
            // order-level tax cannot be combined with them). Totals and the
            // tax of every rated line are computed here from the priced
            // lines; whatever money figures the caller sent are discarded.
            [$totals, $orderItemRows] = $this->computeTotals(
                $orderItemRows,
                $data['discount_type'] ?? null,
                $data['discount_value'] ?? null,
                $data['tax'] ?? 0,
                $data['shipping'] ?? 0,
            );

            $order = new Order(Arr::except($data, self::COMPUTED_COLUMNS));
            // Record whether this order took its lines out of stock, so no
            // later cancel/reject/delete/edit gives back units it never took.
            $order->stock_committed = $adjustStock;
            // Flag the order as backordered when any line kept a remainder, so
            // it can be held from shipping and reconciled when stock arrives.
            $order->backordered_at = collect($lines)->contains(fn (array $l) => $l['backorderedQty'] > 0)
                ? $now
                : null;
            // A preorder line holds the order from shipping until the
            // product's release date, so the order cannot start out shipped
            // or delivered either. The model's hold cannot see the lines yet.
            if ($adjustStock && in_array($order->status, [OrderStatus::SHIPPED, OrderStatus::DELIVERED], true)
                && collect($lines)->contains(fn (array $l) => $l['product']->isPreorder())) {
                throw new InvalidStateException(Order::PREORDER_HOLD_MESSAGE, 'invalid_state_transition');
            }
            $this->applyTotals($order, $totals);
            $order->save();

            // Fill in the order_id-dependent fields and bulk-insert.
            foreach ($orderItemRows as &$row) {
                $row['order_id'] = $order->id;
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
            }
            unset($row);
            OrderItem::insert($orderItemRows);

            foreach ($adjustmentRows as &$adj) {
                $adj['reason'] = "Order {$order->order_number} fulfilled";
                $adj['reference_id'] = $order->id;
            }
            unset($adj);
            if ($adjustStock) {
                StockAdjustment::insert($adjustmentRows);
            }

            // Decrement stock once per unique target — the variant when one was
            // chosen, otherwise the product. This is the fix for variant counts
            // drifting: a line sold as a variant no longer decrements the parent.
            $locationStock = app(ProductLocationStockService::class);
            foreach ($adjustStock ? $perTargetQty : [] as $key => $totalQty) {
                $target = $targets[$key];

                // Each stock target has its own bins, including variants.
                $locationStock->consume($target, $totalQty, $this->fulfillingWarehouseId($order), actor: $creator);

                $target->decrement('stock', $totalQty);
            }

            $order->load('items');

            // Take each kit line's components, through the same audited path
            // as an order edit: bins (order's warehouse first), one ledger row
            // per component, and the line's snapshot rows. The rows were
            // inserted in line order, so they match the lines by position.
            // Runs after the plain lines' decrement above, so the ledger
            // threads one balance for a component sold both ways.
            $kitItemIds = [];
            if ($adjustStock) {
                $insertedItems = $order->items->sortBy('id')->values();
                $kitSale = app(KitSaleService::class);
                foreach ($lines as $position => $line) {
                    if (! $line['isKit']) {
                        continue;
                    }
                    $kitItem = $insertedItems[$position] ?? null;
                    if ($kitItem === null || (int) $kitItem->product_id !== (int) $line['product']->id) {
                        throw new \LogicException('Order item rows do not match the order lines by position.');
                    }
                    $kitItem->setRelation('product', $line['product']);
                    $kitItemIds[$kitItem->id] = true;
                    // The draws checked above, on the locked component rows.
                    $kitSale->take($order, $kitItem, $line['fulfillQty'], $line['draws'], "Order {$order->order_number} fulfilled", $creator, $creator);
                }
            }

            // Allocate serials to each serial-tracked line, pinning the consumed
            // units to their order item so a later cancellation releases exactly
            // those serials. Best-effort during the transition to
            // serials-as-source-of-truth: products without enough tracked
            // serials are left untouched, so creation is unchanged for them.
            // A kit line's components are untracked and were taken above.
            $allocator = app(TrackedStockAllocationService::class);
            foreach ($adjustStock ? $order->items : [] as $orderItem) {
                if (isset($kitItemIds[$orderItem->id])) {
                    continue;
                }
                $product = $products->get($orderItem->product_id);
                $fulfilled = (int) $orderItem->quantity_fulfilled;
                // A variant line draws on the variant's stock, never the
                // parent's serials/batches (replaceItems skips them too), and
                // only the fulfilled portion of a backordered line allocates.
                if ($fulfilled > 0 && $product !== null && $orderItem->product_variant_id === null) {
                    $allocator->allocateForOrderItem($product, $fulfilled, $orderItem, $claimed);
                }
            }

            if ($claimed !== null) {
                $reservations->recordConsumer($claimed, $order);
            }

            return $order;
        }));

        // Fire the action hook once the order and its line items are committed,
        // from the single service every surface (web, REST, GraphQL, MCP) uses —
        // so plugins and webhooks observe order creation consistently, with the
        // full aggregate present (firing on the model `created` event would see
        // an itemless order, since items are inserted after Order::create).
        if ($announce) {
            do_action('order_created', $order, $creator);
        }

        return $order;
    }

    /**
     * Cancel an order and restock its items.
     *
     * Locks the order row and re-reads status inside the transaction so a
     * double-submit or a concurrent ship/cancel cannot restock twice or
     * restock inventory that already left the warehouse. Shared by the web,
     * REST, and GraphQL surfaces (MCP exposes no order mutation).
     *
     * @param  User|null  $actor  Who the restock ledger rows are attributed to.
     *                            Pass it from queued jobs, commands and plugin
     *                            syncs; defaults to the signed-in user, then the
     *                            order's creator (see restockActor()).
     *
     * @throws \RuntimeException When the order has already shipped/delivered.
     *
     * @api
     */
    public function cancel(Order $order, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::CANCELLED) {
                return $locked; // idempotent — never restock twice
            }

            if (in_array($locked->status, [OrderStatus::SHIPPED, OrderStatus::DELIVERED], true)) {
                throw new InvalidStateException(
                    "Cannot cancel an order that has already been {$locked->status->value}.",
                    'invalid_state_transition'
                );
            }

            // Stamped shipped but moved back to pending/processing (rows from
            // before status transitions were enforced): its goods left.
            if ($locked->hasShippedGoods()) {
                throw new InvalidStateException(
                    'Cannot cancel an order whose goods have already shipped.',
                    'invalid_state_transition'
                );
            }

            $this->releaseStock($locked, 'cancel', "Order {$locked->order_number} cancelled", $actor);
            $this->clearBackorder($locked);

            $locked->update(['status' => OrderStatus::CANCELLED]);

            return $locked;
        });
    }

    /**
     * Approve an order that is pending approval.
     *
     * Locks and re-reads the order so a concurrent approve/reject cannot both
     * pass the pending check. Shared by the web and REST surfaces.
     *
     * @throws InvalidStateException When the order was already approved/rejected.
     * @throws AuthorizationException When the approver cannot access the order's warehouse.
     *
     * @api
     */
    public function approve(Order $order, User $approver, ?string $notes = null): Order
    {
        OrderApprovalGate::assertMayApprove($order, $approver);

        $approved = DB::transaction(function () use ($order, $approver, $notes) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // The approver needs access to the order's warehouse. An order
            // with no warehouse (REST, GraphQL and MCP create them) is not
            // tied to one, as in replaceItems().
            if ($locked->warehouse_id !== null) {
                app(WarehouseAccessService::class)->authorizeWarehouse($approver, (int) $locked->warehouse_id);
            }

            if (! $locked->isPendingApproval()) {
                throw new InvalidStateException('Order has already been processed.', 'already_processed');
            }

            // Re-checked on the locked row: who asked for this decision may
            // have changed since the caller loaded the order.
            OrderApprovalGate::assertMayApprove($locked, $approver);

            $locked->update([
                'approval_status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'approval_notes' => $notes,
            ]);

            return $locked;
        });

        // Load the approver relationship for notification
        $approved->load('approver');

        // Send notification to order creator
        NotificationService::createOrderApprovalNotification($approved);

        return $approved;
    }

    /**
     * Reject an order that is pending approval, cancelling it and restoring
     * the stock that was decremented when it was created.
     *
     * @throws InvalidStateException When the order was already approved/rejected.
     * @throws AuthorizationException When the approver cannot access the order's warehouse.
     *
     * @api
     */
    public function reject(Order $order, User $approver, string $notes): Order
    {
        $rejected = DB::transaction(function () use ($order, $approver, $notes) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // The approver needs access to the order's warehouse. An order
            // with no warehouse (REST, GraphQL and MCP create them) is not
            // tied to one, as in replaceItems().
            if ($locked->warehouse_id !== null) {
                app(WarehouseAccessService::class)->authorizeWarehouse($approver, (int) $locked->warehouse_id);
            }

            // isPendingApproval() is false for a cancelled order, whose stock
            // cancel() already gave back: rejecting it would restock twice.
            if (! $locked->isPendingApproval()) {
                throw new InvalidStateException('Order has already been processed.', 'already_processed');
            }

            // Whoever re-opened the order for approval cannot decide it.
            OrderApprovalGate::assertMayReject($locked, $approver);

            if (in_array($locked->status, [OrderStatus::SHIPPED, OrderStatus::DELIVERED], true)) {
                throw new InvalidStateException(
                    "Cannot reject an order that has already been {$locked->status->value}.",
                    'invalid_state_transition'
                );
            }

            // Stock was decremented when the order was created. Rejection has
            // to restore it through the ledger so the inventory count and
            // audit trail line up with what's physically available — without
            // this the rejected order holds phantom reserved stock forever
            // and the reorder logic over-purchases. Refused, like cancel,
            // once any of its goods have left the warehouse.
            try {
                $this->releaseStock($locked, 'reject', "Order {$locked->order_number} rejected", $approver);
            } catch (InvalidStateException $e) {
                throw $e;
            } catch (BusinessRuleException $e) {
                throw new InvalidStateException($e->getMessage(), 'invalid_state_transition');
            }

            $this->clearBackorder($locked);

            $locked->update([
                'approval_status' => 'rejected',
                'status' => 'cancelled',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'approval_notes' => $notes,
            ]);

            return $locked;
        });

        // Load the approver relationship for notification
        $rejected->load('approver');

        // Send notification to order creator
        NotificationService::createOrderApprovalNotification($rejected);

        return $rejected;
    }

    /**
     * Restock a soon-to-be-deleted order's items — but only when the stock it
     * holds is still physically on hand and hasn't already been returned.
     *
     * Stock is decremented at creation, so a pending/processing order still
     * "holds" those units and deleting it has to give them back. A
     * SHIPPED/DELIVERED order's goods have physically left the warehouse, and a
     * CANCELLED order was already restocked by cancel(): restocking either on
     * delete would invent inventory that isn't there. The order row is locked
     * and its status re-read inside this transaction so a concurrent
     * ship/cancel cannot slip a phantom restock past the guard. The caller is
     * responsible for deleting the order itself (web soft-deletes, REST hard-
     * deletes its items first) within the same transaction.
     *
     * @param  User|null  $actor  Who the restock ledger rows are attributed to
     *                            (see cancel()).
     *
     * @api
     */
    public function restockForDeletion(Order $order, ?User $actor = null): void
    {
        DB::transaction(function () use ($order, $actor) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // Returns are recorded against the order's lines and are the
            // audit trail of goods coming back; the order has to stay.
            if (ReturnOrder::withoutGlobalScopes()->where('order_id', $locked->getKey())->exists()) {
                throw new InvalidStateException(
                    'Cannot delete this order: it has returns recorded against it. Cancel it instead.',
                    'invalid_state_transition'
                );
            }

            // Goods already gone (shipped/delivered) or already returned
            // (cancelled) → deletion must not re-inject phantom stock. A row
            // stamped shipped_at but moved back to pending/processing before
            // status transitions were enforced counts as shipped too.
            if ($locked->status === OrderStatus::CANCELLED || $locked->hasShippedGoods()) {
                return;
            }

            // Partially shipped: restocking every line would re-inject the
            // units that already left, so releaseStock() refuses.
            $this->releaseStock($locked, 'delete', "Order {$locked->order_number} deleted", $actor);
            $this->clearBackorder($locked);
        });
    }

    /**
     * Restock a single order line to the stock target that order creation
     * decremented: the variant when the line was sold as one, otherwise the
     * product. Crediting the parent product for a variant line would leave the
     * variant permanently depleted while inflating the parent's on-hand count.
     * A kit line gives back its components as its snapshot recorded them
     * (KitSaleService::giveBack), never the kit's own stock.
     *
     * $quantity is how many units (kits, for a kit line) go back. Callers pass the line's figure
     * from restockableQuantities(), so a line the order never took out of
     * stock (a historical import) or one already returned restocks nothing.
     * The caller must have loaded the item's `product` (and `variant` for
     * variant lines).
     */
    private function restockItem(OrderItem $item, string $reason, Order $order, int $quantity, ?User $actor = null): void
    {
        if ($quantity <= 0) {
            return;
        }

        $viewer = $actor ?? (auth()->user() instanceof User ? auth()->user() : null);
        $access = app(WarehouseAccessService::class);
        if ($viewer !== null && $order->warehouse_id !== null) {
            $access->authorizeWarehouse($viewer, (int) $order->warehouse_id);
        }

        // A kit line took its components, recorded in its snapshot rows, and
        // never the kit's own stock: give those back ($quantity is in kits),
        // with the location checks below applied to each component instead, and
        // leave the kit's row, serials and batches alone. A kit line with no
        // snapshot (sold before kits drew on their components) took the
        // kit's own stock, so it restocks as a plain line.
        if ($item->kitComponents()->withoutGlobalScopes()->exists()) {
            app(KitSaleService::class)->giveBack(
                $item, $quantity, 'order_cancellation', $reason, $order,
                $this->restockActor($order, $actor), $viewer,
            );

            return;
        }

        // Lock the product row FIRST, before releasing tracked records or
        // adjusting stock. create() locks the product then allocates serials/
        // batches and bins; this restock path releases them then adjusts, so
        // without the leading product lock the two acquire (product, serials/
        // batches) in opposite orders and can ABBA-deadlock on a concurrent
        // order creation for the same product. adjust()'s later re-lock is
        // harmlessly re-entrant.
        if ($item->product_id !== null) {
            $lockedProduct = Product::withTrashed()->whereKey($item->product_id)->lockForUpdate()->first();
            $item->setRelation('product', $lockedProduct);
            $item->variant?->setRelation('product', $lockedProduct);
        }

        if ($viewer !== null) {
            $access->authorizeLocation($viewer, $item->product?->location_id);
            // The order does not record its original source bins and returns
            // units to the current primary location. If any possible source
            // is outside the actor's scope, require unrestricted staff rather
            // than moving another warehouse's stock through cancellation.
            $target = $item->variant ?? $item->product;
            if ($target !== null && $access->isRestricted($viewer)) {
                foreach (app(ProductLocationStockService::class)->breakdown($target) as $bin) {
                    $access->authorizeLocation($viewer, (int) $bin->location_id);
                }
            }
            if ($item->product !== null) {
                $this->authorizeTrackedStock($item->product, $viewer);
            }
        }

        // Return any serials this line consumed to available before restocking
        // the count, so the serial records track the goods coming back. No-op
        // for untracked lines and best-effort skips.
        app(TrackedStockAllocationService::class)->releaseForOrderItem($item, $quantity);

        if ($item->product_variant_id !== null && $item->variant !== null) {
            StockAdjustment::adjustVariant(
                $item->variant,
                $quantity,
                'order_cancellation',
                $reason,
                null,
                $order,
                actor: $this->restockActor($order, $actor),
            );

            return;
        }

        if ($item->product !== null) {
            StockAdjustment::adjust(
                $item->product,
                $quantity,
                'order_cancellation',
                $reason,
                null,
                $order,
                actor: $this->restockActor($order, $actor),
                // Booked into the primary bin just below.
                syncBins: false,
            );

            // Return the units to the product's primary location bin so the
            // breakdown rises with the restored total. (Units are restored to
            // the primary location rather than the exact bins they were drawn
            // from — a deliberate simplification; totals stay correct.)
            app(ProductLocationStockService::class)->receive($item->product, $quantity);
        }
    }

    /**
     * The warehouse an order is fulfilled from, whose bins its lines draw
     * down first (ProductLocationStockService::consume). Null when the order
     * names none; the attribute is uncast, so a posted string is normalised.
     */
    private function fulfillingWarehouseId(Order $order): ?int
    {
        return $order->warehouse_id === null || $order->warehouse_id === '' ? null : (int) $order->warehouse_id;
    }

    /**
     * Tracked records have no location of their own. A restricted actor may
     * allocate or release them only when every possible stock location is
     * accessible; otherwise only an unrestricted actor can safely do so.
     */
    private function authorizeTrackedStock(Product $product, User $actor): void
    {
        $access = app(WarehouseAccessService::class);
        if (! $access->isRestricted($actor) || ! in_array($product->tracking_type?->value, ['serial', 'batch'], true)) {
            return;
        }

        $access->authorizeLocation($actor, $product->location_id);
        $bins = app(ProductLocationStockService::class)->breakdown($product);
        foreach ($bins as $bin) {
            if (! $access->canAccessLocation($actor, (int) $bin->location_id)) {
                throw new AuthorizationException('Tracked stock spans warehouses you cannot access; an unrestricted user must fulfil or restock this order.');
            }
        }
    }

    /**
     * Who a ledger row written for $order is attributed to: the caller's
     * $actor, else the signed-in user, else (a queued job or command that
     * passed none) the order's creator, else the organization's first user.
     * stock_adjustments.user_id is required, so a restock must never depend on
     * a session being present.
     */
    private function restockActor(Order $order, ?User $actor): ?User
    {
        $signedIn = auth()->user();

        return $actor
            ?? ($signedIn instanceof User ? $signedIn : null)
            ?? ($order->created_by !== null ? User::withoutGlobalScopes()->find($order->created_by) : null)
            ?? User::withoutGlobalScopes()->where('organization_id', $order->organization_id)->orderBy('id')->first();
    }

    /**
     * How many units of each line can go back to stock when the order is
     * cancelled, rejected, deleted or has its lines replaced: what creating
     * the order took out of stock, less what received returns have already
     * brought back (restocked or written off). Every order restock path goes
     * through this one figure.
     *
     * An order recorded without touching stock (stock_committed false: a
     * historical import) took nothing, so every line is 0.
     *
     * @return array<int, int> order_item_id => units
     *
     * @api
     */
    public function restockableQuantities(Order $order): array
    {
        $order->loadMissing('items');

        if (! $order->stock_committed) {
            return $order->items->mapWithKeys(fn (OrderItem $item) => [$item->id => 0])->all();
        }

        $returned = ReturnOrderItem::query()
            ->whereHas('returnOrder', fn ($query) => $query->withoutGlobalScopes()
                ->where('order_id', $order->getKey())
                ->whereIn('status', ['received', 'completed']))
            ->selectRaw('order_item_id, SUM(quantity) as total_returned')
            ->groupBy('order_item_id')
            ->pluck('total_returned', 'order_item_id');

        // Only the fulfilled portion ever left stock, so only it can come back:
        // backordered units were never decremented. Subtract both the still
        // outstanding backorder and what returns already brought back.
        return $order->items->mapWithKeys(fn (OrderItem $item) => [
            $item->id => max(0, (int) $item->quantity - (int) $item->quantity_backordered - (int) ($returned[$item->id] ?? 0)),
        ])->all();
    }

    /**
     * Clear a cancelled / rejected / deleted order's backorder: the obligation
     * is gone, so zero each line's backordered quantity and drop the order's
     * flag. Runs inside the caller's transaction on the locked order.
     */
    private function clearBackorder(Order $order): void
    {
        $order->items()->where('quantity_backordered', '>', 0)->update(['quantity_backordered' => 0]);

        if ($order->backordered_at !== null) {
            $order->forceFill(['backordered_at' => null])->save();
        }
    }

    /**
     * Give a locked order's stock back as it is cancelled, rejected or
     * deleted: refuse when goods have left or a return is still open, close
     * its open shipments, then restock each line's restockable quantity.
     * Runs inside the caller's transaction, which holds the order lock.
     *
     * @throws \RuntimeException
     */
    private function releaseStock(Order $locked, string $action, string $reason, ?User $actor = null): void
    {
        // A partially shipped order is still pending/processing, but some of
        // its goods have left: restocking every line would invent them.
        $this->assertNoShippedGoods($locked, $action);

        // An open return would restock the same units again when received.
        $openReturn = ReturnOrder::withoutGlobalScopes()
            ->where('order_id', $locked->getKey())
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($openReturn) {
            throw new InvalidStateException(
                "Cannot {$action} this order: it has an open return. Receive or reject the return first.",
                'invalid_state_transition'
            );
        }

        $this->closeOpenShipments($locked, $action);

        // Include products and variants deleted since the order was placed:
        // their units still come back.
        $locked->load([
            'items.product' => fn ($query) => $query->withTrashed(),
            'items.variant' => fn ($query) => $query->withTrashed(),
        ]);
        $restockable = $this->restockableQuantities($locked);

        foreach ($locked->items as $item) {
            $this->restockItem($item, $reason, $locked, $restockable[$item->id] ?? 0, $actor);
        }
    }

    /**
     * Cancel the order's shipments that have not left, so none of them can be
     * marked shipped after its stock went back. A shipment with a bought
     * label is refused instead: the label has to be voided with the carrier,
     * which cancelling that shipment does.
     *
     * @throws \RuntimeException
     */
    private function closeOpenShipments(Order $locked, string $action): void
    {
        $open = Shipment::withoutGlobalScopes()
            ->where('order_id', $locked->getKey())
            ->whereIn('status', [ShipmentStatus::PENDING->value, ShipmentStatus::LABEL_CREATED->value])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($open->contains(fn (Shipment $shipment) => $shipment->status === ShipmentStatus::LABEL_CREATED)) {
            throw new InvalidStateException(
                "Cannot {$action} this order: one of its shipments has a bought label. Cancel that shipment first so the label is voided.",
                'invalid_state_transition'
            );
        }

        foreach ($open as $shipment) {
            $shipment->forceFill(['status' => ShipmentStatus::CANCELLED])->save();

            DB::afterCommit(fn () => do_action('shipment_cancelled', $shipment));
        }
    }

    /**
     * Replace an order's line items wholesale: release everything the order
     * currently holds (stock, serials, batches, and location bins) back to
     * inventory, then re-fulfil the supplied line set through the same audited
     * paths create() uses — bin consume + serial/batch allocation + a
     * variant-aware ledger.
     *
     * The web order edit used to hand-roll per-line stock adjustments that
     * touched neither the per-location bins nor the tracked serial/batch
     * records (and always decremented the parent for variant lines), so every
     * quantity change silently drifted the invariants. Routing edits through
     * this method keeps them consistent.
     *
     * Must run inside the caller's transaction, which holds the order lock.
     *
     * Line discounts are priced and validated here too; the order's totals
     * are left to recalculateTotals(), which the caller runs afterwards.
     *
     * A line may carry tax_rate (the server computes its tax) or, without a
     * rate, a tax amount (kept as given). Tax is not stock: changing only the
     * lines' tax rates or amounts updates them in place, so it is allowed
     * even once the lines are otherwise frozen (shipped, with returns).
     *
     * @param  array<int, array{product_id:int, product_variant_id?:int|null, quantity:int, unit_price?:mixed, discount_type?:string|null, discount_value?:mixed, tax_rate?:mixed, tax?:mixed}>  $items
     * @return string the recomputed gross subtotal (Money string)
     *
     * @api
     */
    public function replaceItems(Order $order, array $items, ?User $actor = null): string
    {
        $viewer = $actor ?? (auth()->user() instanceof User ? auth()->user() : null);
        if ($viewer !== null && $order->warehouse_id !== null) {
            app(WarehouseAccessService::class)->authorizeWarehouse($viewer, (int) $order->warehouse_id);
        }
        $order->load('items.product', 'items.variant');

        // Resubmitting the lines unchanged (an edit that only touches the
        // header, or only the lines' tax) is a no-op for stock. Recreating
        // them would move stock between locations and drop the rows returns
        // and shipments point at.
        $unchanged = $this->matchUnchangedLines($order, $items);
        if ($unchanged !== null) {
            $this->applyLineTaxEdits($unchanged, $items);

            return Money::add('0', ...$order->items->pluck('subtotal')->all());
        }

        // A cancelled order already gave its stock back; new lines would take
        // stock again for an order that will never ship.
        if ($order->status === OrderStatus::CANCELLED) {
            throw new InvalidOrderItemException('A cancelled order\'s line items cannot be changed.');
        }

        // A shipped or delivered order's goods have left: releasing its lines
        // would restock them. Corrections go through a return.
        if ($order->hasShippedGoods()) {
            throw new InvalidOrderItemException('A shipped order\'s line items cannot be changed. Record a return instead.');
        }

        // Shipments point at the order's line rows. Once any are open the
        // lines are frozen, so a shipment never loses the lines it packed.
        if (Shipment::withoutGlobalScopes()->where('order_id', $order->getKey())->active()->exists()) {
            throw new InvalidOrderItemException(
                'Line items cannot be changed once the order has shipments. Cancel its open shipments first.'
            );
        }

        // Returns are recorded against the lines and cap what can come back.
        if (ReturnOrder::withoutGlobalScopes()->where('order_id', $order->getKey())->exists()) {
            throw new InvalidOrderItemException('Line items cannot be changed once the order has returns.');
        }

        // 1. Release the existing lines, then drop them. restockItem locks the
        //    product first, releases serials/batches, restocks the count, and
        //    re-bins — returning inventory to its pre-order state. An order
        //    that never took stock (a historical import) gives nothing back
        //    here and takes nothing below.
        $commitsStock = (bool) $order->stock_committed;
        $restockable = $this->restockableQuantities($order);

        // What each kit already on the order took, read from its snapshot
        // before the release below deletes it. A kit resubmitted by the edit
        // re-takes this composition, never the current BOM, so a BOM change
        // after the sale cannot change what the order holds. An order that
        // never took stock has nothing to re-take.
        $soldKits = $commitsStock ? $this->soldKitCompositions($order) : [];

        // New lines are new terms: an approval decision covered the old ones.
        // The caller's save of the order persists this (see Order).
        $order->requireReapprovalIfDecided($viewer);

        // Remember what each product/variant cost when it was originally sold,
        // so an edit does not re-price lines that were already on the order
        // (even if their quantity grows). Only products/variants new to the
        // order take today's cost. The backfill marker travels with the cost.
        $originalCosts = [];
        foreach ($order->items as $existing) {
            $key = $existing->product_id.':'.($existing->product_variant_id ?? '');
            if (! isset($originalCosts[$key]) || $originalCosts[$key]['unit_cost'] === null) {
                $originalCosts[$key] = [
                    'unit_cost' => $existing->getRawOriginal('unit_cost'),
                    'unit_cost_backfilled_at' => $existing->getRawOriginal('unit_cost_backfilled_at'),
                ];
            }
        }

        // Units already on backorder stay allowed on backorder after an edit,
        // even if the org switch or the product's opt-in has since been
        // turned off: turning backorders off never strands an open order.
        $priorBackordered = [];
        foreach ($order->items as $existing) {
            $key = $existing->product_variant_id !== null
                ? "v{$existing->product_variant_id}"
                : "p{$existing->product_id}";
            $priorBackordered[$key] = ($priorBackordered[$key] ?? 0) + (int) $existing->quantity_backordered;
        }

        foreach ($order->items as $existing) {
            $this->restockItem($existing, "Order {$order->order_number} edited", $order, $restockable[$existing->id] ?? 0, $actor);
            $existing->delete();
        }

        // 2. Lock every product/variant the new lines reference, ordered by id,
        //    before any bin/stock mutation (product-before-bins ordering). A
        //    kit line draws on its components, so they join the same sorted
        //    batch, as in create(): a kit already on the order its snapshot
        //    components (deleted ones included, since their units were taken
        //    and still come and go), a kit new to the order its current BOM.
        $productIds = collect($items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $soldComponentIds = [];
        foreach (array_intersect_key($soldKits, array_flip($productIds)) as $sold) {
            $soldComponentIds = array_merge($soldComponentIds, array_keys($sold['composition']));
        }
        $lockIds = $commitsStock
            ? array_unique(array_merge(
                $productIds,
                $soldComponentIds,
                $this->kitComponentIds(array_values(array_diff($productIds, array_keys($soldKits))), (int) $order->organization_id),
            ))
            : $productIds;
        sort($lockIds);
        $products = Product::whereIn('id', $lockIds)
            ->when($soldComponentIds !== [], fn ($query) => $query->withTrashed())
            ->where('organization_id', $order->organization_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Kits requested per kit product, across the edit's lines.
        $requestedKits = [];
        foreach ($items as $item) {
            $requestedKits[(int) $item['product_id']] = ($requestedKits[(int) $item['product_id']] ?? 0) + (int) $item['quantity'];
        }

        $variantIds = collect($items)->pluck('product_variant_id')->filter()->unique()->sort()->values();
        $variants = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::whereIn('id', $variantIds)
                ->where('organization_id', $order->organization_id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        // 3. Resolve each line's stock draws (its variant, its product, or a
        //    kit's components; see resolveLineDraws) and validate availability
        //    across all lines on the running balances create() uses (see
        //    drawFromBalances).
        $reservations = app(StockReservationService::class);
        $backorderSettings = BackorderSettings::forOrganization((int) $order->organization_id);
        $resolved = [];
        $running = [];
        $available = [];
        foreach (array_values($items) as $index => $item) {
            // The batch may hold deleted kit components; a deleted product is
            // never a line of its own.
            $product = $products->get($item['product_id']);
            if (! $product || $product->trashed()) {
                throw new InvalidOrderItemException("Product not found: {$item['product_id']}");
            }

            $variantId = $item['product_variant_id'] ?? null;
            if ($variantId !== null) {
                $variant = $variants->get($variantId);
                if (! $variant || $variant->product_id !== $product->id) {
                    throw new InvalidOrderItemException("Variant {$variantId} does not belong to product {$product->name}.");
                }
            } else {
                $variant = null;
            }

            // A kit on an order that never took stock (a historical import)
            // takes nothing, so its BOM is not checked, as in create(). A kit
            // already on the order re-takes its snapshot composition (see
            // soldKitDraws); a kit new to the order takes its current BOM. A
            // kit's draws are swapped for the locked component rows; one
            // missing from the batch (the BOM changed since it was read) is
            // locked now, before any balance is read.
            $isKit = $product->isKit();
            if ($isKit && ! $commitsStock) {
                $draws = [];
            } elseif ($isKit && isset($soldKits[$product->id])) {
                $draws = $this->soldKitDraws($product, $variant, $soldKits[$product->id], $requestedKits[$product->id], $products);
            } else {
                $draws = $this->resolveLineDraws($product, $variant);
            }
            if ($isKit) {
                $draws = $this->lockDrawTargets($draws, $products, (int) $order->organization_id);
            }
            $key = $isKit ? null : $draws[0]['key'];

            $qty = (int) $item['quantity'];
            if ($commitsStock && $viewer !== null) {
                $this->authorizeTrackedStock($product, $viewer);
            }
            // Split a short line the same way create() does: fulfil what the
            // running balance covers, backorder the rest when the org switch is
            // on and the product is opted in, otherwise refuse as before. A
            // short kit line is always refused. An order recorded without
            // stock (import) keeps every unit fulfilled.
            $fulfillQty = $qty;
            $backorderedQty = 0;
            if ($commitsStock) {
                $fulfillQty = $this->drawFromBalances(compact('product', 'qty', 'draws', 'isKit'), $running, $available, $reservations);
                $backorderedQty = $qty - $fulfillQty;

                if ($backorderedQty > 0 && ! $backorderSettings->allowsBackorderFor($product)) {
                    $carried = $priorBackordered[$key] ?? 0;
                    if ($backorderedQty > $carried) {
                        throw new InsufficientStockException(
                            "Insufficient stock for {$product->name}. Available: ".max(0, $available[$key]).", requested: {$qty}"
                        );
                    }
                    $priorBackordered[$key] = $carried - $backorderedQty;
                }
            }

            // Price (and validate the discount on) every line before anything
            // is written, so a bad discount cannot leave a half-edited order.
            $unitPrice = $this->unitPriceFor(
                $item, $product, $variant,
                $this->currencyCode($order->currency, (int) $order->organization_id),
                $index,
            );
            $priced = $this->priceLine($unitPrice, $qty, [
                'discount_type' => $item['discount_type'] ?? null,
                'discount_value' => $item['discount_value'] ?? null,
                'tax_rate' => $item['tax_rate'] ?? null,
                'tax' => $item['tax'] ?? null,
            ], $index);

            $resolved[] = compact('item', 'product', 'variant', 'qty', 'fulfillQty', 'backorderedQty', 'unitPrice', 'priced', 'draws', 'isKit');
        }

        // 4. Fulfil each line: create the item, then decrement the right target —
        //    consuming bins + allocating serials/batches for product lines.
        $subtotal = '0';

        $hasBackorder = false;
        foreach ($resolved as $line) {
            $item = $line['item'];
            $product = $line['product'];
            $variant = $line['variant'];
            $qty = $line['qty'];
            $hasBackorder = $hasBackorder || $line['backorderedQty'] > 0;

            $subtotal = Money::add($subtotal, $line['priced']['subtotal']);

            $orderItem = $order->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name' => $product->name,
                'sku' => $variant?->sku ?? $product->sku,
                'quantity' => $qty,
                'quantity_backordered' => $line['backorderedQty'],
                'unit_price' => $line['unitPrice'],
            ] + $line['priced'] + ($originalCosts[$product->id.':'.($variant?->id ?? '')] ?? [
                'unit_cost' => OrderItem::costAtSale($product, $variant),
                'unit_cost_backfilled_at' => null,
            ]));

            if (! $commitsStock) {
                continue;
            }

            // Only the fulfilled portion leaves stock; a backordered remainder
            // waits for reconciliation. A kit line takes its components (the
            // draws checked above, on the locked rows) and records them in
            // its snapshot rows; the kit's own stock is never touched.
            if ($line['isKit']) {
                app(KitSaleService::class)->take($order, $orderItem, $line['fulfillQty'], $line['draws'], "Order {$order->order_number} edited", $actor, $viewer);

                continue;
            }
            $this->fulfilLine($order, $product, $variant, $orderItem, $line['fulfillQty'], "Order {$order->order_number} edited", $actor, $viewer);
        }

        // Keep the order's backorder flag in step with the new line split: keep
        // the original timestamp when still backordered (FIFO order preserved),
        // stamp now if newly backordered, clear it when nothing remains.
        $order->forceFill([
            'backordered_at' => $hasBackorder ? ($order->backordered_at ?? now()) : null,
        ])->save();

        return $subtotal;
    }

    /**
     * Fulfil `$qty` units of one order line from stock: draw the line's bins
     * (preferring the order's warehouse), write the `order_fulfillment` ledger
     * row, and allocate serials/batches for a product line. Shared by the order
     * edit (replaceItems) and backorder reconciliation so both move stock the
     * same audited way — never a bare decrement. Must run inside the caller's
     * transaction with the product/variant row locked; a non-positive $qty is a
     * no-op. $viewer gates warehouse access (null = unrestricted), $actor is the
     * ledger attribution (resolved through restockActor).
     *
     * @internal
     */
    public function fulfilLine(
        Order $order,
        Product $product,
        ?ProductVariant $variant,
        OrderItem $orderItem,
        int $qty,
        string $reason,
        ?User $actor = null,
        ?User $viewer = null,
        ?Collection $claimed = null,
    ): void {
        if ($qty <= 0) {
            return;
        }

        $warehouseId = $this->fulfillingWarehouseId($order);
        $ledgerActor = $this->restockActor($order, $actor);
        $locationStock = app(ProductLocationStockService::class);

        if ($variant !== null) {
            $locationStock->consume($variant, $qty, $warehouseId, actor: $viewer);
            StockAdjustment::adjustVariant(
                $variant, -$qty, 'order_fulfillment',
                $reason, null, $order, allowNegative: false,
                actor: $ledgerActor,
                syncBins: false,
                preferWarehouseId: $warehouseId,
            );

            return;
        }

        // consume() before adjust() so the lazy bin seed reads the
        // pre-decrement total; allocate after the ledger row.
        $locationStock->consume($product, $qty, $warehouseId, actor: $viewer);
        StockAdjustment::adjust(
            $product, -$qty, 'order_fulfillment',
            $reason, null, $order, allowNegative: false,
            actor: $ledgerActor,
            syncBins: false,
        );
        app(TrackedStockAllocationService::class)->allocateForOrderItem($product, $qty, $orderItem, $claimed);
    }

    /**
     * Recompute an existing order's totals from its stored line items.
     *
     * Used after an edit (lines replaced, or the order discount, tax or
     * shipping changed). Sets the computed columns on the model without
     * saving it; the caller saves as part of its own update. Must run inside
     * the caller's transaction with the order row locked, because it checks
     * the new total against what has already been paid.
     *
     * @param  mixed  $orderTax  order-level tax only; per-line taxes are added from the items
     *
     * @throws ValidationException
     */
    public function recalculateTotals(
        Order $order,
        DiscountType|string|null $discountType,
        mixed $discountValue,
        mixed $orderTax,
        mixed $shipping,
    ): void {
        $items = $order->items()->orderBy('id')->get()->keyBy('id');
        $lines = $items->map(fn (OrderItem $item) => [
            'subtotal' => $item->subtotal,
            'discount_amount' => $item->discount_amount,
            'tax_rate' => $item->tax_rate,
            'tax' => $item->tax,
        ])->all();

        [$totals, $lines] = $this->computeTotals($lines, $discountType, $discountValue, $orderTax, $shipping);

        // A rated line's tax follows its taxable amount, which an order
        // discount change moves. Lines without a rate are left exactly as
        // stored (older rows included). Save only lines that changed.
        foreach ($lines as $id => $line) {
            $item = $items[$id];
            if ($item->tax_rate === null) {
                continue;
            }
            $item->tax = $line['tax'];
            $item->total = $line['total'];
            if ($item->isDirty(['tax', 'total'])) {
                $item->save();
            }
        }

        $this->applyTotals($order, $totals);
    }

    /**
     * Change only the order-level discount of an existing order, keeping its
     * lines, tax and shipping, and recompute the totals under the order's row
     * lock. Used by the REST and GraphQL order updates, which do not edit
     * lines.
     *
     * @throws ValidationException
     */
    public function changeOrderDiscount(Order $order, DiscountType|string|null $type, mixed $value): Order
    {
        return DB::transaction(function () use ($order, $type, $value) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $this->recalculateTotals($locked, $type, $value, $this->orderLevelTax($locked), $locked->shipping);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Move an order to a fulfilment status (processing, shipped, delivered)
     * through the same path the order edit uses: lock, re-read, stamp
     * shipped_at / delivered_at once, and update the model so OrderObserver
     * sends the status notifications and webhooks. Stock is untouched: it left
     * inventory when the order was created. Cancelling has its own restocking
     * path, cancel().
     *
     * @throws \RuntimeException When the order is cancelled or delivered, or is
     *                           shipped and $to is not delivered.
     *
     * @api
     */
    public function transitionStatus(Order $order, OrderStatus $to): Order
    {
        if ($to === OrderStatus::CANCELLED) {
            throw new \InvalidArgumentException('Use OrderService::cancel() to cancel an order.');
        }

        return DB::transaction(function () use ($order, $to) {
            $locked = Order::withoutGlobalScopes()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === $to) {
                return $locked;
            }

            // Ship-together backstop: an order with outstanding backorders is
            // held from shipping until every line's stock arrives. The primary
            // guard is at ShipmentService::create(); this covers a direct move.
            if (in_array($to, [OrderStatus::SHIPPED, OrderStatus::DELIVERED], true) && $locked->backordered_at !== null) {
                throw new InvalidStateException(Order::BACKORDER_HOLD_MESSAGE, 'invalid_state_transition');
            }
            if (in_array($to, [OrderStatus::SHIPPED, OrderStatus::DELIVERED], true) && $locked->awaitingReleaseUntil() !== null) {
                throw new InvalidStateException(Order::PREORDER_HOLD_MESSAGE, 'invalid_state_transition');
            }

            $error = $locked->transitionErrorTo($to);
            if ($error !== null) {
                throw new InvalidStateException($error, 'invalid_state_transition');
            }

            if ($locked->approvalBlocks($to)) {
                throw new InvalidStateException(Order::APPROVAL_PENDING_MESSAGE, 'approval_pending');
            }

            $attributes = ['status' => $to];

            if (in_array($to, [OrderStatus::SHIPPED, OrderStatus::DELIVERED], true) && ! $locked->shipped_at) {
                $attributes['shipped_at'] = now();
            }

            if ($to === OrderStatus::DELIVERED && ! $locked->delivered_at) {
                $attributes['delivered_at'] = now();
            }

            $locked->update($attributes);

            return $locked;
        });
    }

    /**
     * Correct an order marked shipped by mistake: move it back to processing
     * and clear shipped_at. The only step back the status rules allow (see
     * Order::statusTransitionError()), and only when:
     *
     *  - the actor is an admin of the order's organization;
     *  - the order is shipped (not delivered);
     *  - none of its shipments has left the warehouse (pending or label
     *    created only), so no goods are actually gone;
     *  - it has no returns.
     *
     * Stock is untouched. The correction is written to the activity log.
     *
     * @throws AuthorizationException When the actor is not an admin there.
     * @throws InvalidStateException When the conditions above do not hold.
     *
     * @api
     */
    public function revertMistakenShipment(Order $order, User $actor): Order
    {
        if (! $actor->isAdmin() || (int) $actor->organization_id !== (int) $order->organization_id) {
            throw new AuthorizationException('Only an administrator can correct a mistaken shipped status.');
        }

        return DB::transaction(function () use ($order, $actor) {
            $locked = Order::withoutGlobalScopes()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::SHIPPED) {
                throw new InvalidStateException('Only an order marked shipped can be moved back to processing.', 'invalid_state_transition');
            }

            if (Shipment::withoutGlobalScopes()->where('order_id', $locked->getKey())->leftWarehouse()->exists()) {
                throw new InvalidStateException('This order has a shipment that left the warehouse, so it really shipped. Record a return for goods that come back.', 'invalid_state_transition');
            }

            if (ReturnOrder::withoutGlobalScopes()->where('order_id', $locked->getKey())->exists()) {
                throw new InvalidStateException('This order has returns recorded against it, so its shipped status cannot be corrected.', 'invalid_state_transition');
            }

            $shippedAt = $locked->shipped_at?->toIso8601String();
            $locked->permitMistakenShipmentRevert();
            $locked->update(['status' => OrderStatus::PROCESSING, 'shipped_at' => null]);

            ActivityLog::create([
                'organization_id' => $locked->organization_id,
                'user_id' => $actor->id,
                'subject_type' => Order::class,
                'subject_id' => $locked->getKey(),
                'action' => 'shipment_reverted',
                'description' => "Corrected order {$locked->order_number} from shipped back to processing (marked shipped by mistake)",
                'properties' => ['previous_status' => OrderStatus::SHIPPED->value, 'previous_shipped_at' => $shippedAt],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $locked;
        });
    }

    /**
     * An order created waiting for approval cannot start out shipped or
     * delivered.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertCreatableWhileAwaitingApproval(array $data): void
    {
        $approval = $data['approval_status'] instanceof OrderApprovalStatus
            ? $data['approval_status']
            : OrderApprovalStatus::tryFrom((string) $data['approval_status']);
        $status = $data['status'] ?? null;
        $status = $status instanceof OrderStatus ? $status : OrderStatus::tryFrom((string) $status);

        if ($approval === OrderApprovalStatus::PENDING && in_array($status, Order::STATUSES_BLOCKED_BY_APPROVAL, true)) {
            throw ValidationException::withMessages(['status' => Order::APPROVAL_PENDING_MESSAGE]);
        }
    }

    /**
     * Refuse a restocking action when any of the order's shipments has left
     * the warehouse. Runs inside the caller's transaction on a locked order.
     *
     * @throws \RuntimeException
     */
    public function assertNoShippedGoods(Order $order, string $action): void
    {
        $shipped = Shipment::withoutGlobalScopes()
            ->where('order_id', $order->getKey())
            ->leftWarehouse()
            ->exists();

        if ($shipped) {
            throw new InvalidStateException(
                "Cannot {$action} this order: some of its shipments have already left the warehouse.",
                'invalid_state_transition'
            );
        }
    }

    /**
     * Apply the tax fields of resubmitted, otherwise unchanged lines in place.
     * A submitted line that sends neither tax_rate nor tax keeps its stored
     * tax. One that sends either is read the way priceLine() reads a new
     * line: a rate wins (its tax is computed by recalculateTotals()); without
     * a rate, the tax amount sent (or none) is kept as given.
     *
     * @param  array<int, OrderItem>  $matched  submitted index => current line
     * @param  array<int, array<string, mixed>>  $items
     */
    private function applyLineTaxEdits(array $matched, array $items): void
    {
        foreach ($matched as $index => $item) {
            $submitted = $items[$index];
            if (! array_key_exists('tax_rate', $submitted) && ! array_key_exists('tax', $submitted)) {
                continue;
            }

            $rate = LineTax::rate($submitted['tax_rate'] ?? null);
            $item->tax_rate = $rate;
            if ($rate === null) {
                $item->tax = Money::of($submitted['tax'] ?? 0);
                $item->total = Money::add(Money::subtract($item->subtotal, $item->discount_amount), $item->tax);
            }
            if ($item->isDirty(['tax_rate', 'tax', 'total'])) {
                $item->save();
            }
        }
    }

    /**
     * Match a submitted line set to the order's current lines: same product,
     * variant, quantity and line discount, and (when submitted) the same unit
     * price. Tax is not compared (see applyLineTaxEdits()).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, OrderItem>|null submitted index => current line, or null when the lines changed
     */
    private function matchUnchangedLines(Order $order, array $items): ?array
    {
        $money = fn ($v) => ($v === null || $v === '') ? null : Money::of($v);

        $current = $order->items->map(fn (OrderItem $i) => [
            'item' => $i,
            'key' => ((int) $i->product_id).'|'.((int) ($i->product_variant_id ?? 0)).'|'.((int) $i->quantity),
            'unit_price' => $money($i->unit_price),
            'discount_type' => $i->discount_type instanceof \BackedEnum ? $i->discount_type->value : ($i->discount_type ?: null),
            'discount_value' => $money($i->discount_value ?? null),
        ])->values()->all();

        if (count($current) !== count($items)) {
            return null;
        }

        $matched = [];
        foreach ($items as $submittedIndex => $submitted) {
            $key = ((int) ($submitted['product_id'] ?? 0)).'|'.((int) ($submitted['product_variant_id'] ?? 0)).'|'.((int) ($submitted['quantity'] ?? 0));
            $found = null;

            foreach ($current as $index => $line) {
                if ($line['key'] !== $key) {
                    continue;
                }
                if (array_key_exists('unit_price', $submitted) && $money($submitted['unit_price']) !== $line['unit_price']) {
                    continue;
                }
                // A line submitted without a discount means no discount (the
                // same way priceLine() reads it), so dropping a discount is a
                // change, not an unchanged resubmission.
                $submittedType = ($submitted['discount_type'] ?? null) ?: null;
                $submittedValue = $money($submitted['discount_value'] ?? null);
                if ($submittedType === null || $submittedValue === null) {
                    $submittedType = null;
                }
                if ($submittedType !== $line['discount_type']) {
                    continue;
                }
                if ($line['discount_type'] !== null && $submittedValue !== $line['discount_value']) {
                    continue;
                }

                $found = $index;
                break;
            }

            if ($found === null) {
                return null;
            }

            $matched[$submittedIndex] = $current[$found]['item'];
            unset($current[$found]);
        }

        return $matched;
    }

    /**
     * The part of an order's tax that was entered at order level: the stored
     * tax minus the per-line taxes it already includes.
     */
    public function orderLevelTax(Order $order): string
    {
        return Money::max(
            Money::subtract($order->tax, Money::add(...$order->items()->pluck('tax')->all())),
            0,
        );
    }

    /**
     * The unit price of a line in the order's currency.
     *
     * An explicit unit_price always wins. Otherwise the price must be one set
     * in the order's currency:
     *  - an order in the product's own currency takes the variant's own price,
     *    else the product's selling price, else its list price;
     *  - an order in another currency takes the product's price for that
     *    currency (price_in_currencies), unless the line is a variant with a
     *    price of its own, which that product-level figure does not describe.
     * With neither, the line is rejected rather than silently charged a price
     * set in another currency.
     *
     * @param  array<string, mixed>  $item
     *
     * @throws ValidationException when no price exists in the order's currency
     */
    private function unitPriceFor(array $item, Product $product, ?ProductVariant $variant, string $currency, int $index): mixed
    {
        if (($item['unit_price'] ?? null) !== null && $item['unit_price'] !== '') {
            return $item['unit_price'];
        }

        $productCurrency = $this->currencyCode($product->currency, (int) $product->organization_id);

        if ($currency === $productCurrency) {
            return $variant?->price ?? $product->selling_price ?? $product->price ?? 0;
        }

        $prices = array_change_key_case((array) ($product->price_in_currencies ?? []), CASE_UPPER);
        $price = $prices[$currency] ?? null;

        if ($variant?->price === null && is_numeric($price)) {
            return $price;
        }

        $label = $variant !== null
            ? "{$product->name} (".($variant->title ?? $variant->sku ?? "variant {$variant->id}").')'
            : $product->name;

        throw ValidationException::withMessages([
            "items.{$index}.unit_price" => "{$label} has no price in {$currency}. Enter a unit price for this line.",
        ]);
    }

    /**
     * An upper-case currency code, or the organization's base currency when
     * none is given.
     */
    private function currencyCode(mixed $currency, int $organizationId): string
    {
        return filled($currency)
            ? strtoupper(trim((string) $currency))
            : Organization::currencyFor($organizationId);
    }

    /**
     * Price one line: gross subtotal, its resolved discount, tax and total.
     *
     * A line with a tax_rate gets its tax from computeTotals() (any tax
     * amount sent with it is ignored); a line without one keeps the tax
     * amount it was given.
     *
     * @param  array<string, mixed>  $item  may carry discount_type, discount_value, tax_rate, tax
     * @return array{subtotal: string, discount_type: string|null, discount_value: string|null, discount_amount: string, tax_rate: string|null, tax: string, total: string}
     *
     * @throws ValidationException when the discount is invalid for this line
     */
    private function priceLine(mixed $unitPrice, int $quantity, array $item, int $index): array
    {
        $gross = Money::multiply($unitPrice, $quantity);

        [$type, $value, $discount] = $this->resolveDiscount(
            $gross,
            $item['discount_type'] ?? null,
            $item['discount_value'] ?? null,
            "items.{$index}",
        );

        $rate = LineTax::rate($item['tax_rate'] ?? null);
        $tax = $rate === null ? Money::of($item['tax'] ?? 0) : '0.00';

        return [
            'subtotal' => $gross,
            'discount_type' => $type,
            'discount_value' => $value,
            'discount_amount' => $discount,
            'tax_rate' => $rate,
            'tax' => $tax,
            'total' => Money::add(Money::subtract($gross, $discount), $tax),
        ];
    }

    /**
     * Order totals from priced lines.
     *
     * Tax base rule: discounts come off merchandise only, before tax. The
     * order-level discount applies to the merchandise net of line discounts
     * (never to tax or shipping); shipping is added last, untaxed. The
     * stored discount_amount is the whole discount (lines + order), so
     * subtotal - discount_amount + tax + shipping = total.
     *
     * Tax is one of two kinds, never both:
     *  - Line tax. A line with a tax_rate is taxed by LineTax: its taxable
     *    amount is its net (after its line discount) less its share of the
     *    order discount, and the tax per rate is rounded once and split back
     *    across that rate's lines. A line without a rate keeps the tax amount
     *    it carries (imports, API). The order's tax is the sum.
     *  - Order-level tax: one amount for the whole order, added to the sum of
     *    any line tax amounts (the behaviour before line rates existed).
     * An order-level tax is refused when any line has a rate, so a rate can
     * never be taxed twice.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, array{subtotal: mixed, discount_amount?: mixed, tax_rate?: mixed, tax?: mixed}>  $lines  in line order
     * @return array{0: array<string, string|null>, 1: array<TKey, array<string, mixed>>} the order totals, and the lines with tax and total recomputed
     *
     * @throws ValidationException
     */
    private function computeTotals(array $lines, DiscountType|string|null $discountType, mixed $discountValue, mixed $orderTax, mixed $shipping): array
    {
        $subtotal = Money::add(...array_map(fn (array $l) => $l['subtotal'] ?? 0, array_values($lines)));
        $lineDiscounts = Money::add(...array_map(fn (array $l) => $l['discount_amount'] ?? 0, array_values($lines)));

        $net = Money::subtract($subtotal, $lineDiscounts);
        [$type, $value, $orderDiscount] = $this->resolveDiscount($net, $discountType, $discountValue, null);

        if (! Money::isZero($orderTax) && LineTax::anyRated($lines)) {
            throw ValidationException::withMessages([
                'tax' => 'An order-level tax cannot be combined with line tax rates. Remove the order tax, or clear the rates on the lines.',
            ]);
        }

        $taxed = LineTax::compute(array_map(fn (array $l) => [
            'net' => Money::subtract($l['subtotal'] ?? 0, $l['discount_amount'] ?? 0),
            'tax_rate' => $l['tax_rate'] ?? null,
            'tax' => $l['tax'] ?? 0,
        ], $lines), $orderDiscount);

        foreach ($lines as $key => $line) {
            $lines[$key]['tax_rate'] = $taxed[$key]['tax_rate'];
            $lines[$key]['tax'] = $taxed[$key]['tax'];
            $lines[$key]['total'] = Money::add(
                Money::subtract($line['subtotal'] ?? 0, $line['discount_amount'] ?? 0),
                $taxed[$key]['tax'],
            );
        }

        $lineTax = Money::add(...array_column($taxed, 'tax'));
        $discount = Money::add($lineDiscounts, $orderDiscount);
        $tax = Money::add($orderTax, $lineTax);
        $shipping = Money::of($shipping);

        return [[
            'subtotal' => $subtotal,
            'discount_type' => $type,
            'discount_value' => $value,
            'discount_amount' => $discount,
            'tax' => $tax,
            'shipping' => $shipping,
            'total' => Money::add(Money::subtract($subtotal, $discount), $tax, $shipping),
        ], $lines];
    }

    /**
     * Set computed totals on the order. This is the single choke point every
     * create and edit goes through, and so the one place the documented
     * `order_total_calculation` plugin filter runs. On create the order it
     * receives is not saved yet (it has no id).
     *
     * Rejects a total below zero, and a total below what the customer has
     * already paid (void or refund payments before cutting the order down).
     * Re-derives the payment status against the new total.
     *
     * @param  array<string, string|null>  $totals
     *
     * @throws ValidationException
     */
    private function applyTotals(Order $order, array $totals): void
    {
        $order->fill(Arr::except($totals, ['total']));

        $total = apply_filters(self::TOTAL_FILTER, $totals['total'], $order);

        if (! is_numeric($total) || Money::isNegative((string) $total)) {
            throw ValidationException::withMessages([
                'total' => 'The order total cannot be negative.',
            ]);
        }

        $order->total = Money::of((string) $total);

        if (! $order->exists) {
            $order->amount_paid = '0.00';
            $order->payment_status = PaymentStatus::derive($order->total, '0', '0');

            return;
        }

        $order->syncPaymentState();

        if (Money::compare($order->total, $order->amount_paid) < 0) {
            throw ValidationException::withMessages([
                'total' => "The order total ({$order->total}) cannot be less than the {$order->amount_paid} already paid. Void or refund payments first.",
            ]);
        }
    }

    /**
     * Resolve a discount against the amount it applies to.
     *
     * @param  string|null  $prefix  error-key prefix ("items.0"), or null for the order
     * @return array{0: string|null, 1: string|null, 2: string} [type, entered value, money amount]
     *
     * @throws ValidationException
     */
    private function resolveDiscount(string $base, DiscountType|string|null $type, mixed $value, ?string $prefix): array
    {
        $key = fn (string $field): string => $prefix === null ? $field : "{$prefix}.{$field}";

        if ($type === null || $type === '' || $value === null || $value === '') {
            return [null, null, '0.00'];
        }

        $type = $type instanceof DiscountType ? $type : DiscountType::tryFrom((string) $type);
        if ($type === null) {
            throw ValidationException::withMessages([
                $key('discount_type') => 'The discount type must be percent or fixed.',
            ]);
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw ValidationException::withMessages([
                $key('discount_value') => 'The discount cannot be negative.',
            ]);
        }

        // Normalise scientific notation or long floats before bcmath sees it.
        $value = Money::of(number_format((float) $value, 2, '.', ''));

        if ($type === DiscountType::PERCENT) {
            if (Money::compare($value, 100) > 0) {
                throw ValidationException::withMessages([
                    $key('discount_value') => 'A percentage discount cannot exceed 100%.',
                ]);
            }

            return [$type->value, $value, Money::percentOf($base, $value)];
        }

        if (Money::compare($value, $base) > 0) {
            throw ValidationException::withMessages([
                $key('discount_value') => "The discount ({$value}) cannot exceed the amount it applies to ({$base}).",
            ]);
        }

        return [$type->value, $value, $value];
    }

    /**
     * The reservation an order fulfils: both `reservation_owner` and
     * `reservation_reference`, or neither.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string|null, 1: string|null}
     *
     * @throws ValidationException
     */
    private function reservationFrom(array $data): array
    {
        $owner = trim((string) ($data['reservation_owner'] ?? ''));
        $reference = trim((string) ($data['reservation_reference'] ?? ''));

        if ($owner === '' && $reference === '') {
            return [null, null];
        }

        if ($owner === '' || $reference === '') {
            throw ValidationException::withMessages([
                'reservation_reference' => 'An order fulfilling a reservation needs both reservation_owner and reservation_reference.',
            ]);
        }

        return [$owner, $reference];
    }

    /**
     * The stock a line draws, per unit of the line. A plain line draws one
     * unit of its variant (when chosen) or its product per unit sold; a kit
     * line draws `per` units of each component per kit (KitSaleService::draws,
     * which refuses a kit that cannot be sold). Keys are shared, so a
     * component on a kit line and the same product on a plain line meet on
     * one running balance. The caller validates that $variant belongs to
     * $product and must swap each target for its locked row before reading
     * stock.
     *
     * @return array<int, array{target: Product|ProductVariant, key: string, per: int}>
     *
     * @throws InvalidOrderItemException
     */
    private function resolveLineDraws(Product $product, ?ProductVariant $variant): array
    {
        if ($product->isKit()) {
            $this->refuseKitVariant($product, $variant);

            return app(KitSaleService::class)->draws($product);
        }

        if ($variant !== null) {
            return [['target' => $variant, 'key' => "v{$variant->id}", 'per' => 1]];
        }

        if ($product->has_variants) {
            throw new InvalidOrderItemException(
                "{$product->name} is sold by variant; each line item needs a product_variant_id."
            );
        }

        return [['target' => $product, 'key' => "p{$product->id}", 'per' => 1]];
    }

    /**
     * The component product ids of the kits among $productIds, read before
     * the products are locked so they join the same sorted lock batch.
     *
     * @param  array<int, int|string>  $productIds
     * @return array<int, int>
     */
    private function kitComponentIds(array $productIds, int $organizationId): array
    {
        if ($productIds === []) {
            return [];
        }

        return ProductComponent::query()
            ->whereIn('parent_product_id', Product::withoutGlobalScopes()
                ->select('id')
                ->whereIn('id', $productIds)
                ->where('organization_id', $organizationId)
                ->where('type', 'kit'))
            ->pluck('component_product_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @throws InvalidOrderItemException When a kit line names a variant.
     */
    private function refuseKitVariant(Product $kit, ?ProductVariant $variant): void
    {
        if ($variant !== null) {
            throw new InvalidOrderItemException(
                "Kit \"{$kit->name}\" cannot be sold by variant."
            );
        }
    }

    /**
     * What each kit on the order took, from its lines' snapshot rows, keyed
     * by kit product id: the composition (component product id => quantity
     * per kit) and how many of that kit the order held in total. Read before
     * replaceItems releases the lines, which deletes the snapshot rows.
     *
     * The same kit on several lines with different compositions (sold
     * before and after a BOM change, then merged into one order) is rare;
     * the composition of its first line, ordered by id, is used. A kit line
     * with no snapshot rows (sold before kits drew on their components) has
     * no composition to keep, so that kit takes its current BOM.
     *
     * @return array<int, array{composition: array<int, int>, quantity: int}>
     */
    private function soldKitCompositions(Order $order): array
    {
        $kitLines = $order->items
            ->filter(fn (OrderItem $item) => $item->product?->isKit() === true)
            ->sortBy('id');
        if ($kitLines->isEmpty()) {
            return [];
        }

        $rowsByLine = OrderItemKitComponent::withoutGlobalScopes()
            ->whereIn('order_item_id', $kitLines->pluck('id')->all())
            ->orderBy('id')
            ->get()
            ->groupBy('order_item_id');

        $sold = [];
        foreach ($kitLines as $line) {
            $kitId = (int) $line->product_id;
            $rows = $rowsByLine->get($line->id);
            if ($rows !== null && ! isset($sold[$kitId])) {
                $sold[$kitId] = [
                    'composition' => $rows->mapWithKeys(fn (OrderItemKitComponent $row) => [
                        (int) $row->component_product_id => (int) $row->quantity_per_kit,
                    ])->all(),
                    'quantity' => 0,
                ];
            }
        }
        foreach ($kitLines as $line) {
            if (isset($sold[(int) $line->product_id])) {
                $sold[(int) $line->product_id]['quantity'] += (int) $line->quantity;
            }
        }

        return $sold;
    }

    /**
     * The draws of a kit already on the order: its snapshot composition (see
     * soldKitCompositions), on the locked component rows, instead of the
     * current BOM. The kit's sellability rules are not re-applied to what it
     * already took, but a component deleted since the sale cannot go into
     * more kits than the order held: up to $sold['quantity'] kits in all may
     * be re-taken; $requested beyond that is refused.
     *
     * @param  array{composition: array<int, int>, quantity: int}  $sold
     * @param  Collection<int, Product>  $products  the locked batch, deleted components included
     * @return array<int, array{target: Product, key: string, per: int}>
     *
     * @throws InvalidOrderItemException
     */
    private function soldKitDraws(Product $kit, ?ProductVariant $variant, array $sold, int $requested, Collection $products): array
    {
        $this->refuseKitVariant($kit, $variant);

        $draws = [];
        foreach ($sold['composition'] as $componentId => $per) {
            $component = $products->get($componentId);
            if ($component === null) {
                throw new InvalidOrderItemException('A kit component could not be found.');
            }
            if ($component->trashed() && $requested > $sold['quantity']) {
                throw new InvalidOrderItemException(
                    "Kit \"{$kit->name}\" contains component \"{$component->name}\", which has been deleted,"
                    ." so this order cannot have more than {$sold['quantity']} of the kit."
                );
            }
            $draws[] = ['target' => $component, 'key' => 'p'.$componentId, 'per' => $per];
        }

        return $draws;
    }

    /**
     * Swap each kit draw's target for its locked row and return the draws.
     * A target not yet in the locked batch (a component added to the kit
     * between the BOM read and the lock) is locked now, in id order, and
     * added to $products. Shared by create() and replaceItems().
     *
     * @param  array<int, array{target: Product, key: string, per: int}>  $draws
     * @param  Collection<int, Product>  $products
     * @return array<int, array{target: Product, key: string, per: int}>
     */
    private function lockDrawTargets(array $draws, Collection &$products, int $organizationId): array
    {
        $missing = collect($draws)
            ->map(fn (array $d) => (int) $d['target']->id)
            ->reject(fn (int $id) => $products->has($id))
            ->unique()
            ->sort()
            ->values();

        if ($missing->isNotEmpty()) {
            $locked = Product::whereIn('id', $missing->all())
                ->where('organization_id', $organizationId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($locked->count() !== $missing->count()) {
                throw new InvalidOrderItemException('A kit component could not be found.');
            }

            $products = $products->union($locked);
        }

        foreach ($draws as $d => $draw) {
            $draws[$d]['target'] = $products[(int) $draw['target']->id];
        }

        return $draws;
    }

    /**
     * Draw one order line on the running balances of its stock draws (see
     * resolveLineDraws) and return how many units, or kits for a kit line,
     * they cover. Lines sharing a draw key (the same product or variant, or a
     * component sold both in a kit and on its own) meet on one balance, which
     * starts at the target's available stock (on hand less active
     * reservations) read under the row lock. A kit line draws `per` units of
     * each component per kit. The covered units come off every balance the
     * line draws on. A kit line the balances cannot cover in full is refused,
     * since kits are never backordered; what happens to a short plain line is
     * the caller's backorder rule. Shared by create() and replaceItems().
     *
     * @param  array{product: Product, qty: int, draws: array<int, array{target: Product|ProductVariant, key: string, per: int}>, isKit: bool}  $line
     * @param  array<string, int>  $running  balance left per draw key
     * @param  array<string, int>  $available  starting balance per draw key
     *
     * @throws InsufficientStockException When a kit line is short.
     */
    private function drawFromBalances(array $line, array &$running, array &$available, StockReservationService $reservations): int
    {
        $fulfillQty = $line['qty'];
        $scarcest = null;
        foreach ($line['draws'] as $d) {
            $available[$d['key']] ??= (int) $d['target']->stock - $this->reservedFor($reservations, $d['target']);
            $balance = $running[$d['key']] ?? $available[$d['key']];
            $covers = intdiv(max(0, $balance), $d['per']);
            if ($scarcest === null || $covers < $scarcest[1]) {
                $scarcest = [$d['target'], $covers];
            }
            $fulfillQty = min($fulfillQty, $covers);
        }

        if ($line['isKit'] && $fulfillQty < $line['qty']) {
            throw new InsufficientStockException(
                "Insufficient stock for kit {$line['product']->name}: component {$scarcest[0]->name} runs out first."
                ." Available: {$fulfillQty}, Requested: {$line['qty']}"
            );
        }

        foreach ($line['draws'] as $d) {
            $running[$d['key']] = ($running[$d['key']] ?? $available[$d['key']]) - $fulfillQty * $d['per'];
        }

        return $fulfillQty;
    }

    /**
     * Units of a stock target held by active reservations: a variant's holds,
     * or a product's.
     */
    private function reservedFor(StockReservationService $reservations, Product|ProductVariant $target): int
    {
        return $target instanceof ProductVariant
            ? $reservations->reservedTotal((int) $target->product_id, (int) $target->id)
            : $reservations->reservedTotal((int) $target->id, null);
    }

    /**
     * Human-readable label for an insufficient-stock message.
     *
     * @param  array{product: Product, variant: ?ProductVariant}  $line
     */
    private function lineLabel(array $line): string
    {
        if ($line['variant'] !== null) {
            $variant = $line['variant'];
            $descriptor = $variant->title ?? $variant->sku ?? "variant {$variant->id}";

            return "{$line['product']->name} ({$descriptor})";
        }

        return $line['product']->name;
    }
}
