<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TrackingType;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductSerial;
use App\Models\Order\OrderItem;

/**
 * Allocates and releases tracked units (serials) as orders are fulfilled and
 * unwound, so the tracking records move with the goods instead of drifting.
 *
 * This is the first step toward serials being the source of truth for stock.
 * It is deliberately best-effort during the transition: a serial-tracked
 * product is only allocated when it actually has enough available serials, so
 * order creation keeps working exactly as before for products whose serials
 * have not been populated yet. The #178 reconciliation surfaces any remaining
 * drift. Batch consumption (FEFO) is a planned follow-up.
 */
final class TrackedStockAllocationService
{
    /**
     * Allocate serials to a fulfilled order line: mark the oldest available
     * serials sold and pin them to the order item so they can be released on
     * cancellation. Skips (returns 0) any product that is not serial-tracked
     * or does not have at least $quantity available serials.
     *
     * Runs inside the order transaction, which already holds a row lock on the
     * product; the serials are additionally locked here.
     *
     * @return int the number of serials allocated (0 when skipped)
     */
    public function allocateForOrderItem(Product $product, int $quantity, OrderItem $orderItem): int
    {
        if ($quantity <= 0 || $this->trackingType($product) !== TrackingType::SERIAL) {
            return 0;
        }

        $available = ProductSerial::query()
            ->where('product_id', $product->id)
            ->where('status', ProductSerial::STATUS_AVAILABLE)
            ->orderBy('id')
            ->lockForUpdate()
            ->limit($quantity)
            ->get();

        if ($available->count() < $quantity) {
            // Not fully tracked — leave the serials alone rather than
            // partially allocating or failing the order.
            return 0;
        }

        foreach ($available as $serial) {
            $serial->update([
                'status' => ProductSerial::STATUS_SOLD,
                'order_item_id' => $orderItem->id,
            ]);
        }

        return $available->count();
    }

    /**
     * Release the serials that were allocated to this order line, returning
     * them to available and clearing the link. No-op when nothing was
     * allocated (the common case for untracked products and best-effort skips).
     *
     * @return int the number of serials released
     */
    public function releaseForOrderItem(OrderItem $orderItem): int
    {
        $serials = ProductSerial::query()
            ->where('order_item_id', $orderItem->id)
            ->where('status', ProductSerial::STATUS_SOLD)
            ->lockForUpdate()
            ->get();

        foreach ($serials as $serial) {
            $serial->update([
                'status' => ProductSerial::STATUS_AVAILABLE,
                'order_item_id' => null,
            ]);
        }

        return $serials->count();
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
