<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\User;
use App\Support\SequenceNumberRetry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
     *
     * @throws \Exception When a product is missing or stock is insufficient.
     * @throws QueryException On unrecoverable DB errors.
     */
    public function create(array $data, User $creator, string $source = 'manual'): Order
    {
        $data['organization_id'] = $creator->organization_id;
        $data['created_by'] = $creator->id;
        $data['source'] = $source;
        $data['approval_status'] ??= 'pending';

        return SequenceNumberRetry::create(fn () => DB::transaction(function () use ($data, $creator) {
            $data['order_number'] = Order::generateOrderNumber($data['organization_id']);

            // Batch-lock every referenced product in a single SELECT ... FOR
            // UPDATE so concurrent orders can't race the read-modify-write.
            $productIds = array_unique(array_column($data['items'], 'product_id'));
            $products = Product::whereIn('id', $productIds)
                ->where('organization_id', $data['organization_id'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Validate availability across ALL items first. Multiple line items
            // of the same product accumulate against the same running stock.
            $running = [];
            foreach ($data['items'] as $item) {
                $pid = $item['product_id'];
                if (! $products->has($pid)) {
                    throw new \Exception("Product not found: {$pid}");
                }
                $running[$pid] = ($running[$pid] ?? (int) $products[$pid]->stock) - (int) $item['quantity'];
                if ($running[$pid] < 0) {
                    $product = $products[$pid];
                    throw new InsufficientStockException("Insufficient stock for {$product->name}. Available: {$product->stock}, Requested: {$item['quantity']}");
                }
            }

            // Build order-item rows + stock-adjustment rows. quantity_before and
            // quantity_after thread the running stock so the ledger is faithful
            // when the order touches the same product twice.
            $subtotal = 0;
            $itemTaxTotal = 0;
            $orderItemRows = [];
            $now = now();
            $adjustmentRows = [];
            $perProductQty = [];
            $threadStock = [];

            foreach ($data['items'] as $item) {
                $pid = $item['product_id'];
                $product = $products[$pid];
                $qty = (int) $item['quantity'];

                // unit_price is optional: API callers may omit it and fall back
                // to the product's selling/list price. Web callers always send
                // it, so the fallback is inert there.
                $unitPrice = $item['unit_price'] ?? $product->selling_price ?? $product->price ?? 0;
                $itemTax = $item['tax'] ?? 0;

                $itemSubtotal = $qty * $unitPrice;
                $subtotal += $itemSubtotal;
                $itemTaxTotal += $itemTax;

                $orderItemRows[] = [
                    'product_id' => $pid,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $itemSubtotal,
                    'tax' => $itemTax,
                    'total' => $itemSubtotal + $itemTax,
                ];

                $perProductQty[$pid] = ($perProductQty[$pid] ?? 0) + $qty;
                $beforeForEntry = $threadStock[$pid] ?? (int) $product->stock;
                $afterForEntry = $beforeForEntry - $qty;
                $threadStock[$pid] = $afterForEntry;

                $adjustmentRows[] = [
                    'organization_id' => $data['organization_id'],
                    'product_id' => $pid,
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
            $data['subtotal'] = $subtotal;
            $data['tax'] = ($data['tax'] ?? 0) + $itemTaxTotal;
            $data['shipping'] = $data['shipping'] ?? 0;
            $data['total'] = $subtotal + $data['tax'] + $data['shipping'];

            $order = Order::create($data);

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
            StockAdjustment::insert($adjustmentRows);

            // Decrement stock once per unique product instead of once per line.
            foreach ($perProductQty as $pid => $totalQty) {
                $products[$pid]->decrement('stock', $totalQty);
            }

            return $order;
        }));
    }
}
