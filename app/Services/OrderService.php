<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\ReturnOrder;
use App\Models\Order\ReturnOrderItem;
use App\Models\Shipping\Shipment;
use App\Models\User;
use App\Support\Money;
use App\Support\OrderApprovalGate;
use App\Support\SequenceNumberRetry;
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
     * @throws \Exception When a product is missing or stock is insufficient.
     * @throws QueryException On unrecoverable DB errors.
     */
    public function create(array $data, User $creator, string $source = 'manual', bool $adjustStock = true, bool $announce = true): Order
    {
        $data['organization_id'] = $creator->organization_id;
        $data['created_by'] = $creator->id;
        $data['source'] = $source;
        $data['approval_status'] ??= 'pending';

        $order = SequenceNumberRetry::create(fn () => DB::transaction(function () use ($data, $creator, $adjustStock) {
            $orgId = $data['organization_id'];
            $data['order_number'] = Order::generateOrderNumber($orgId);

            // Batch-lock every referenced product (and variant) in single
            // SELECT ... FOR UPDATE queries so concurrent orders can't race the
            // read-modify-write on stock.
            $productIds = array_unique(array_column($data['items'], 'product_id'));
            $products = Product::whereIn('id', $productIds)
                ->where('organization_id', $orgId)
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

            // Resolve each line's stock target once: a specific variant when one
            // is chosen (validating ownership), otherwise the product itself.
            // A variant-tracked product requires a variant on every new line.
            $lines = [];
            foreach (array_values($data['items']) as $index => $item) {
                $pid = $item['product_id'];
                if (! $products->has($pid)) {
                    throw new \Exception("Product not found: {$pid}");
                }
                $product = $products[$pid];
                $variantId = $item['product_variant_id'] ?? null;

                if ($variantId !== null) {
                    $variant = $variants->get($variantId);
                    if (! $variant || $variant->product_id !== $product->id) {
                        throw new InvalidOrderItemException(
                            "Variant {$variantId} does not belong to product {$product->name}."
                        );
                    }
                    $target = $variant;
                    $key = "v{$variantId}";
                } else {
                    if ($product->has_variants) {
                        throw new InvalidOrderItemException(
                            "{$product->name} is sold by variant; each line item needs a product_variant_id."
                        );
                    }
                    $variant = null;
                    $target = $product;
                    $key = "p{$pid}";
                }

                $lines[] = compact('item', 'product', 'variant', 'target', 'key', 'index')
                    + ['qty' => (int) $item['quantity']];
            }

            // Validate availability across ALL lines first. Lines sharing a
            // target (same product, or same variant) accumulate against one
            // running balance.
            $running = [];
            foreach ($adjustStock ? $lines : [] as $line) {
                $key = $line['key'];
                $running[$key] = ($running[$key] ?? (int) $line['target']->stock) - $line['qty'];
                if ($running[$key] < 0) {
                    throw new InsufficientStockException(
                        'Insufficient stock for '.$this->lineLabel($line)
                        .". Available: {$line['target']->stock}, Requested: {$line['qty']}"
                    );
                }
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
                $key = $line['key'];
                $targets[$key] = $line['target'];

                // unit_price is optional: callers may omit it and fall back to
                // the variant's own price (when a variant is chosen) or the
                // product's selling/list price.
                $unitPrice = $item['unit_price']
                    ?? $variant?->price
                    ?? $product->selling_price
                    ?? $product->price
                    ?? 0;
                // Price the line and validate its discount before anything is
                // written, so a bad discount rejects the whole order cleanly.
                $orderItemRows[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    'sku' => $variant?->sku ?? $product->sku,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    // Cost at the time of sale, for margin and turnover reports.
                    'unit_cost' => OrderItem::costAtSale($product, $variant),
                ] + $this->priceLine($unitPrice, $qty, $item, $line['index']);

                $perTargetQty[$key] = ($perTargetQty[$key] ?? 0) + $qty;
                $beforeForEntry = $threadStock[$key] ?? (int) $line['target']->stock;
                $afterForEntry = $beforeForEntry - $qty;
                $threadStock[$key] = $afterForEntry;

                $adjustmentRows[] = [
                    'organization_id' => $orgId,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'user_id' => $creator->id,
                    'type' => 'order_fulfillment',
                    'quantity_before' => $beforeForEntry,
                    'quantity_after' => $afterForEntry,
                    'adjustment_quantity' => -$qty,
                    'reason' => null,  // set after order_number known
                    'notes' => null,
                    'reference_type' => Order::class,
                    'reference_id' => null,  // set after $order is created
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Order tax = any order-level tax (web) plus the sum of per-line
            // taxes (API). Exactly one side is non-zero per surface today, so
            // this preserves both: web keeps its order-level tax with zero line
            // tax; API keeps its summed line tax with no order-level tax.
            // Totals are computed here from the priced lines; whatever money
            // figures the caller sent are discarded.
            $totals = $this->computeTotals(
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

                // Draw the sold units out of the product's location bins before
                // dropping the total, so the per-location breakdown never claims
                // more than exists. Products only (bins are per-product; variant
                // stock has no location breakdown).
                if ($target instanceof Product) {
                    $locationStock->consume($target, $totalQty);
                }

                $target->decrement('stock', $totalQty);
            }

            // Allocate serials to each serial-tracked line, pinning the consumed
            // units to their order item so a later cancellation releases exactly
            // those serials. Best-effort during the transition to
            // serials-as-source-of-truth: products without enough tracked
            // serials are left untouched, so creation is unchanged for them.
            $order->load('items');
            $allocator = app(TrackedStockAllocationService::class);
            foreach ($adjustStock ? $order->items : [] as $orderItem) {
                $product = $products->get($orderItem->product_id);
                // A variant line draws on the variant's stock, never the
                // parent's serials/batches (replaceItems skips them too).
                if ($product !== null && $orderItem->product_variant_id === null) {
                    $allocator->allocateForOrderItem($product, (int) $orderItem->quantity, $orderItem);
                }
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
     * @throws \RuntimeException When the order has already shipped/delivered.
     */
    public function cancel(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::CANCELLED) {
                return $locked; // idempotent — never restock twice
            }

            if (in_array($locked->status, [OrderStatus::SHIPPED, OrderStatus::DELIVERED], true)) {
                throw new \RuntimeException(
                    "Cannot cancel an order that has already been {$locked->status->value}."
                );
            }

            $this->releaseStock($locked, 'cancel', "Order {$locked->order_number} cancelled");

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
     */
    public function approve(Order $order, User $approver, ?string $notes = null): Order
    {
        OrderApprovalGate::assertMayApprove($order, $approver);

        $approved = DB::transaction(function () use ($order, $approver, $notes) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPendingApproval()) {
                throw new InvalidStateException('Order has already been processed.', 'already_processed');
            }

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
     */
    public function reject(Order $order, User $approver, string $notes): Order
    {
        $rejected = DB::transaction(function () use ($order, $approver, $notes) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // isPendingApproval() is false for a cancelled order, whose stock
            // cancel() already gave back: rejecting it would restock twice.
            if (! $locked->isPendingApproval()) {
                throw new InvalidStateException('Order has already been processed.', 'already_processed');
            }

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
                $this->releaseStock($locked, 'reject', "Order {$locked->order_number} rejected");
            } catch (InvalidStateException $e) {
                throw $e;
            } catch (\RuntimeException $e) {
                throw new InvalidStateException($e->getMessage(), 'invalid_state_transition');
            }

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
     */
    public function restockForDeletion(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // Returns are recorded against the order's lines and are the
            // audit trail of goods coming back; the order has to stay.
            if (ReturnOrder::withoutGlobalScopes()->where('order_id', $locked->getKey())->exists()) {
                throw new \RuntimeException(
                    'Cannot delete this order: it has returns recorded against it. Cancel it instead.'
                );
            }

            // Goods already gone (shipped/delivered) or already returned
            // (cancelled) → deletion must not re-inject phantom stock.
            if (in_array($locked->status, [OrderStatus::SHIPPED, OrderStatus::DELIVERED, OrderStatus::CANCELLED], true)) {
                return;
            }

            // Partially shipped: restocking every line would re-inject the
            // units that already left, so releaseStock() refuses.
            $this->releaseStock($locked, 'delete', "Order {$locked->order_number} deleted");
        });
    }

    /**
     * Restock a single order line to the stock target that order creation
     * decremented: the variant when the line was sold as one, otherwise the
     * product. Crediting the parent product for a variant line would leave the
     * variant permanently depleted while inflating the parent's on-hand count.
     *
     * $quantity is how many units go back. Callers pass the line's figure
     * from restockableQuantities(), so a line the order never took out of
     * stock (a historical import) or one already returned restocks nothing.
     * The caller must have loaded the item's `product` (and `variant` for
     * variant lines).
     */
    private function restockItem(OrderItem $item, string $reason, Order $order, int $quantity): void
    {
        if ($quantity <= 0) {
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
            Product::whereKey($item->product_id)->lockForUpdate()->first();
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
                $order
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
                $order
            );

            // Return the units to the product's primary location bin so the
            // breakdown rises with the restored total. (Units are restored to
            // the primary location rather than the exact bins they were drawn
            // from — a deliberate simplification; totals stay correct.)
            app(ProductLocationStockService::class)->receive($item->product, $quantity);
        }
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

        return $order->items->mapWithKeys(fn (OrderItem $item) => [
            $item->id => max(0, (int) $item->quantity - (int) ($returned[$item->id] ?? 0)),
        ])->all();
    }

    /**
     * Give a locked order's stock back as it is cancelled, rejected or
     * deleted: refuse when goods have left or a return is still open, close
     * its open shipments, then restock each line's restockable quantity.
     * Runs inside the caller's transaction, which holds the order lock.
     *
     * @throws \RuntimeException
     */
    private function releaseStock(Order $locked, string $action, string $reason): void
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
            throw new \RuntimeException(
                "Cannot {$action} this order: it has an open return. Receive or reject the return first."
            );
        }

        $this->closeOpenShipments($locked, $action);

        $locked->load('items.product', 'items.variant');
        $restockable = $this->restockableQuantities($locked);

        foreach ($locked->items as $item) {
            $this->restockItem($item, $reason, $locked, $restockable[$item->id] ?? 0);
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
            throw new \RuntimeException(
                "Cannot {$action} this order: one of its shipments has a bought label. Cancel that shipment first so the label is voided."
            );
        }

        foreach ($open as $shipment) {
            $shipment->forceFill(['status' => ShipmentStatus::CANCELLED])->save();
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
     * @param  array<int, array{product_id:int, product_variant_id?:int|null, quantity:int, unit_price?:mixed, discount_type?:string|null, discount_value?:mixed}>  $items
     * @return string the recomputed gross subtotal (Money string)
     */
    public function replaceItems(Order $order, array $items): string
    {
        $order->load('items.product', 'items.variant');

        // Resubmitting the lines unchanged (an edit that only touches the
        // header) is a no-op. Recreating them would move stock between
        // locations and drop the rows returns and shipments point at.
        if ($this->linesUnchanged($order, $items)) {
            return Money::add('0', ...$order->items->pluck('subtotal')->all());
        }

        // A cancelled order already gave its stock back; new lines would take
        // stock again for an order that will never ship.
        if ($order->status === OrderStatus::CANCELLED) {
            throw new InvalidOrderItemException('A cancelled order\'s line items cannot be changed.');
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

        foreach ($order->items as $existing) {
            $this->restockItem($existing, "Order {$order->order_number} edited", $order, $restockable[$existing->id] ?? 0);
            $existing->delete();
        }

        // 2. Lock every product/variant the new lines reference, ordered by id,
        //    before any bin/stock mutation (product-before-bins ordering).
        $productIds = collect($items)->pluck('product_id')->filter()->unique()->sort()->values();
        $products = Product::whereIn('id', $productIds)
            ->where('organization_id', $order->organization_id)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $variantIds = collect($items)->pluck('product_variant_id')->filter()->unique()->sort()->values();
        $variants = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::whereIn('id', $variantIds)
                ->where('organization_id', $order->organization_id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        // 3. Resolve each line's target (variant vs product) and validate
        //    availability across all lines against a running balance.
        $resolved = [];
        $running = [];
        foreach (array_values($items) as $index => $item) {
            $product = $products->get($item['product_id']);
            if (! $product) {
                throw new InvalidOrderItemException("Product not found: {$item['product_id']}");
            }

            $variantId = $item['product_variant_id'] ?? null;
            if ($variantId !== null) {
                $variant = $variants->get($variantId);
                if (! $variant || $variant->product_id !== $product->id) {
                    throw new InvalidOrderItemException("Variant {$variantId} does not belong to product {$product->name}.");
                }
                $target = $variant;
                $key = "v{$variantId}";
            } else {
                if ($product->has_variants) {
                    throw new InvalidOrderItemException("{$product->name} is sold by variant; each line item needs a product_variant_id.");
                }
                $variant = null;
                $target = $product;
                $key = "p{$product->id}";
            }

            $qty = (int) $item['quantity'];
            $running[$key] = ($running[$key] ?? (int) $target->stock) - $qty;
            if ($commitsStock && $running[$key] < 0) {
                throw new InsufficientStockException(
                    "Insufficient stock for {$product->name}. Available: {$target->stock}, requested: {$qty}"
                );
            }

            // Price (and validate the discount on) every line before anything
            // is written, so a bad discount cannot leave a half-edited order.
            $unitPrice = $item['unit_price'] ?? $variant?->price ?? $product->selling_price ?? $product->price ?? 0;
            $priced = $this->priceLine($unitPrice, $qty, [
                'discount_type' => $item['discount_type'] ?? null,
                'discount_value' => $item['discount_value'] ?? null,
            ], $index);

            $resolved[] = compact('item', 'product', 'variant', 'qty', 'unitPrice', 'priced');
        }

        // 4. Fulfil each line: create the item, then decrement the right target —
        //    consuming bins + allocating serials/batches for product lines.
        $subtotal = '0';
        $locationStock = app(ProductLocationStockService::class);
        $allocator = app(TrackedStockAllocationService::class);

        foreach ($resolved as $line) {
            $item = $line['item'];
            $product = $line['product'];
            $variant = $line['variant'];
            $qty = $line['qty'];

            $subtotal = Money::add($subtotal, $line['priced']['subtotal']);

            $orderItem = $order->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name' => $product->name,
                'sku' => $variant?->sku ?? $product->sku,
                'quantity' => $qty,
                'unit_price' => $line['unitPrice'],
            ] + $line['priced'] + ($originalCosts[$product->id.':'.($variant?->id ?? '')] ?? [
                'unit_cost' => OrderItem::costAtSale($product, $variant),
                'unit_cost_backfilled_at' => null,
            ]));

            if (! $commitsStock) {
                continue;
            }

            if ($variant !== null) {
                StockAdjustment::adjustVariant(
                    $variant, -$qty, 'order_fulfillment',
                    "Order {$order->order_number} edited", null, $order, allowNegative: false,
                );
            } else {
                // consume() before adjust() so the lazy bin seed reads the
                // pre-decrement total; allocate after the item exists.
                $locationStock->consume($product, $qty);
                StockAdjustment::adjust(
                    $product, -$qty, 'order_fulfillment',
                    "Order {$order->order_number} edited", null, $order, allowNegative: false,
                );
                $allocator->allocateForOrderItem($product, $qty, $orderItem);
            }
        }

        return $subtotal;
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
        $lines = $order->items()->get(['subtotal', 'discount_amount', 'tax'])
            ->map(fn (OrderItem $item) => [
                'subtotal' => $item->subtotal,
                'discount_amount' => $item->discount_amount,
                'tax' => $item->tax,
            ])
            ->all();

        $totals = $this->computeTotals($lines, $discountType, $discountValue, $orderTax, $shipping);

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
     * @throws \RuntimeException When the order is cancelled.
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

            if ($locked->status === OrderStatus::CANCELLED) {
                throw new \RuntimeException('A cancelled order cannot be reactivated. Create a new order instead.');
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
            throw new \RuntimeException(
                "Cannot {$action} this order: some of its shipments have already left the warehouse."
            );
        }
    }

    /**
     * Whether a submitted line set matches the order's current lines: same
     * product, variant, quantity and line discount, and (when submitted) the
     * same unit price.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function linesUnchanged(Order $order, array $items): bool
    {
        $money = fn ($v) => ($v === null || $v === '') ? null : Money::of($v);

        $current = $order->items->map(fn (OrderItem $i) => [
            'key' => ((int) $i->product_id).'|'.((int) ($i->product_variant_id ?? 0)).'|'.((int) $i->quantity),
            'unit_price' => $money($i->unit_price),
            'discount_type' => $i->discount_type instanceof \BackedEnum ? $i->discount_type->value : ($i->discount_type ?: null),
            'discount_value' => $money($i->discount_value ?? null),
        ])->values()->all();

        if (count($current) !== count($items)) {
            return false;
        }

        foreach ($items as $submitted) {
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
                return false;
            }

            unset($current[$found]);
        }

        return true;
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
     * Price one line: gross subtotal, its resolved discount, tax and total.
     *
     * @param  array<string, mixed>  $item  may carry discount_type, discount_value, tax
     * @return array{subtotal: string, discount_type: string|null, discount_value: string|null, discount_amount: string, tax: string, total: string}
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

        $tax = Money::of($item['tax'] ?? 0);

        return [
            'subtotal' => $gross,
            'discount_type' => $type,
            'discount_value' => $value,
            'discount_amount' => $discount,
            'tax' => $tax,
            'total' => Money::add(Money::subtract($gross, $discount), $tax),
        ];
    }

    /**
     * Order totals from priced lines.
     *
     * Tax base rule: discounts come off merchandise only, before tax. The
     * order-level discount applies to the merchandise net of line discounts
     * (never to tax or shipping); tax is an amount supplied on those
     * discounted figures; shipping is added last. The stored discount_amount
     * is the whole discount (lines + order), so
     * subtotal - discount_amount + tax + shipping = total.
     *
     * @param  array<int, array{subtotal: mixed, discount_amount?: mixed, tax?: mixed}>  $lines
     * @return array<string, string|null>
     */
    private function computeTotals(array $lines, DiscountType|string|null $discountType, mixed $discountValue, mixed $orderTax, mixed $shipping): array
    {
        $subtotal = Money::add(...array_map(fn (array $l) => $l['subtotal'] ?? 0, $lines));
        $lineDiscounts = Money::add(...array_map(fn (array $l) => $l['discount_amount'] ?? 0, $lines));
        $lineTax = Money::add(...array_map(fn (array $l) => $l['tax'] ?? 0, $lines));

        $net = Money::subtract($subtotal, $lineDiscounts);
        [$type, $value, $orderDiscount] = $this->resolveDiscount($net, $discountType, $discountValue, null);

        $discount = Money::add($lineDiscounts, $orderDiscount);
        $tax = Money::add($orderTax, $lineTax);
        $shipping = Money::of($shipping);

        return [
            'subtotal' => $subtotal,
            'discount_type' => $type,
            'discount_value' => $value,
            'discount_amount' => $discount,
            'tax' => $tax,
            'shipping' => $shipping,
            'total' => Money::add(Money::subtract($subtotal, $discount), $tax, $shipping),
        ];
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
