<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TrackingType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Work-order operations shared by the web WorkOrderController, the REST API
 * and the MCP server.
 */
final class WorkOrderService
{
    /**
     * Statuses in which a work order has never consumed or produced stock,
     * so deleting it leaves nothing to reverse.
     */
    public const DELETABLE_STATUSES = ['draft', 'cancelled'];

    /**
     * Start against the current status, serialized with completion,
     * cancellation and deletion. A stale request must not reopen finished work.
     */
    public function start(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder): void {
            $locked = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['draft', 'pending'], true)) {
                throw new InvalidStateException('Only draft or pending work orders can be started.', 'invalid_status');
            }

            $locked->load(['product', 'items.product']);
            $this->assertProductsSupported($locked->items->pluck('product')->prepend($locked->product));

            foreach ($locked->items as $item) {
                $remaining = $item->quantity_required - $item->quantity_consumed;
                if ($item->product->stock < $remaining) {
                    throw new InsufficientStockException("Insufficient stock for component '{$item->product->name}'. Available: {$item->product->stock}, Required: {$remaining}.");
                }
            }

            $locked->update(['status' => 'in_progress', 'started_at' => now()]);
        });
    }

    /**
     * Work orders currently move product totals and bins only. Refuse stock
     * held on serials, batches or variants until production can book those
     * records too, including when a product changed after the order was made.
     *
     * @param  iterable<Product|null>  $products
     */
    public function assertProductsSupported(iterable $products): void
    {
        foreach ($products as $product) {
            if ($product === null) {
                throw new InvalidStateException('A work order product is no longer available.', 'invalid_product');
            }

            if ($product->has_variants || $product->isKit()
                || ($product->tracking_type !== null && $product->tracking_type !== TrackingType::NONE)) {
                throw new InvalidStateException(
                    "Work orders cannot consume or produce '{$product->name}': kits and products tracked by variant, serial or batch are not supported.",
                    'unsupported_tracking',
                );
            }
        }
    }

    /**
     * Delete a draft or cancelled work order and its component lines.
     *
     * @throws InvalidStateException when the work order has started, completed or otherwise moved stock
     */
    public function delete(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder) {
            // Lock and re-check so a concurrent start cannot slip in between
            // the guard and the delete.
            $locked = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::DELETABLE_STATUSES, true)) {
                throw new InvalidStateException('Only draft or cancelled work orders can be deleted.', 'invalid_status');
            }

            $locked->items()->delete();
            $locked->delete();
        });
    }
}
