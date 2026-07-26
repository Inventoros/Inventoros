<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TrackingType;
use App\Exceptions\InsufficientStockException;
use App\Models\Inventory\OrderItemBatchAllocation;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductSerial;
use App\Models\Order\OrderItem;

/**
 * Allocates and releases tracked units (serials) as orders are fulfilled and
 * unwound, so the tracking records move with the goods instead of drifting.
 *
 * This is the first step toward the tracking records being the source of
 * truth for stock. It is deliberately best-effort during the transition: a
 * tracked product is only allocated when it actually has enough serials /
 * batch quantity, so order creation keeps working exactly as before for
 * products whose tracking records have not been populated yet. The #178
 * reconciliation surfaces any remaining drift.
 */
final class TrackedStockAllocationService
{
    /**
     * Allocate tracked units to a fulfilled order line, dispatching on the
     * product's tracking type: serials are marked sold and pinned to the line;
     * batches are consumed FEFO and recorded per batch. Skips (returns 0) any
     * product that is not tracked, or does not have at least $quantity
     * available.
     *
     * Runs inside the order transaction, which already holds a row lock on the
     * product; the tracking records are additionally locked here.
     *
     * @return int the number of units allocated (0 when skipped)
     */
    public function allocateForOrderItem(Product $product, int $quantity, OrderItem $orderItem): int
    {
        if ($quantity <= 0) {
            return 0;
        }

        return match ($this->trackingType($product)) {
            TrackingType::SERIAL => $this->allocateSerials($product, $quantity, $orderItem),
            TrackingType::BATCH => $this->allocateBatches($product, $quantity, $orderItem),
            default => 0,
        };
    }

    /**
     * Release whatever tracked units this order line consumed — serials back to
     * available, batch quantities back to their batches. No-op when nothing was
     * allocated (untracked products and best-effort skips).
     *
     * @return int the number of units released
     */
    public function releaseForOrderItem(OrderItem $orderItem): int
    {
        return $this->releaseSerials($orderItem) + $this->releaseBatches($orderItem);
    }

    /**
     * Mark the oldest available serials sold and pin them to the order line.
     * Skips unless at least $quantity serials are available.
     */
    private function allocateSerials(Product $product, int $quantity, OrderItem $orderItem): int
    {
        $available = ProductSerial::query()
            ->where('product_id', $product->id)
            ->where('status', ProductSerial::STATUS_AVAILABLE)
            ->orderBy('id')
            ->lockForUpdate()
            ->limit($quantity)
            ->get();

        if ($available->count() < $quantity) {
            if ($this->strictMode()) {
                throw new InsufficientStockException(
                    "Not enough tracked serials for {$product->name}: {$available->count()} available, {$quantity} required."
                );
            }

            // Not fully tracked — leave the serials alone rather than
            // partially allocating or failing the order.
            return 0;
        }

        foreach ($available as $serial) {
            // order_item_id is not mass-assignable; set it directly.
            $serial->status = ProductSerial::STATUS_SOLD;
            $serial->order_item_id = $orderItem->id;
            $serial->save();
        }

        return $available->count();
    }

    private function releaseSerials(OrderItem $orderItem): int
    {
        $serials = ProductSerial::query()
            ->where('order_item_id', $orderItem->id)
            ->where('status', ProductSerial::STATUS_SOLD)
            ->lockForUpdate()
            ->get();

        foreach ($serials as $serial) {
            $serial->status = ProductSerial::STATUS_AVAILABLE;
            $serial->order_item_id = null;
            $serial->save();
        }

        return $serials->count();
    }

    /**
     * Consume $quantity from the product's batches, first-expiry-first-out
     * (earliest expiry first, undated batches last), recording how much came
     * from each batch. Skips unless the batches together hold at least
     * $quantity, so an under-tracked product is left untouched.
     */
    private function allocateBatches(Product $product, int $quantity, OrderItem $orderItem): int
    {
        $batches = ProductBatch::query()
            ->where('product_id', $product->id)
            ->where('quantity', '>', 0)
            // expiry_date IS NULL sorts undated batches last; then soonest
            // expiry first, then oldest row.
            ->orderByRaw('expiry_date is null')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $totalAvailable = (int) $batches->sum('quantity');

        if ($totalAvailable < $quantity) {
            if ($this->strictMode()) {
                throw new InsufficientStockException(
                    "Not enough tracked batch quantity for {$product->name}: {$totalAvailable} available, {$quantity} required."
                );
            }

            return 0;
        }

        $remaining = $quantity;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $batch->quantity);
            $batch->decrement('quantity', $take);

            OrderItemBatchAllocation::create([
                'organization_id' => $product->organization_id,
                'order_item_id' => $orderItem->id,
                'product_batch_id' => $batch->id,
                'quantity' => $take,
            ]);

            $remaining -= $take;
        }

        return $quantity;
    }

    private function releaseBatches(OrderItem $orderItem): int
    {
        $allocations = OrderItemBatchAllocation::query()
            ->where('order_item_id', $orderItem->id)
            ->lockForUpdate()
            ->get();

        $released = 0;

        foreach ($allocations as $allocation) {
            ProductBatch::query()
                ->whereKey($allocation->product_batch_id)
                ->increment('quantity', $allocation->quantity);

            $released += (int) $allocation->quantity;
            $allocation->delete();
        }

        return $released;
    }

    /**
     * When strict mode is on, tracked records are authoritative: an order for
     * a tracked product must be fully covered by serials/batches or it is
     * rejected. Off (default) is best-effort — under-populated products are
     * left untouched.
     */
    private function strictMode(): bool
    {
        return (bool) config('inventory.strict_tracked_stock', false);
    }

    private function trackingType(Product $product): ?TrackingType
    {
        $type = $product->tracking_type;

        if ($type instanceof TrackingType) {
            return $type;
        }

        return $type === null ? null : TrackingType::tryFrom((string) $type);
    }
}
