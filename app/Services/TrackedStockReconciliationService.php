<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TrackingType;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\StockAdjustment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Surfaces (and optionally corrects) drift between a product's recorded
 * stock and its batch/serial tracking records.
 *
 * For batch/serial-tracked products the tracking tables are maintained
 * independently of products.stock, so the two can silently diverge — a
 * batch received without a matching adjustment, a serial marked sold
 * outside the normal fulfilment path, and so on. Until batch/serial
 * records become the authoritative source of stock, this service gives
 * operators a way to detect that drift and, when they choose, to correct
 * products.stock to match the tracking records through the same audited
 * adjustment path every other stock change uses.
 */
final class TrackedStockReconciliationService
{
    /**
     * The tracked total for a product is:
     *  - batch:  the sum of its batch quantities;
     *  - serial: the count of its serials that are still available.
     *
     * @return Collection<int, array{
     *     product: Product,
     *     tracking_type: string,
     *     recorded_stock: int,
     *     tracked_total: int,
     *     difference: int
     * }>
     */
    public function discrepancies(int $organizationId): Collection
    {
        return Product::query()
            ->where('organization_id', $organizationId)
            ->whereIn('tracking_type', [TrackingType::BATCH->value, TrackingType::SERIAL->value])
            ->get()
            ->map(function (Product $product): array {
                $type = $product->tracking_type instanceof TrackingType
                    ? $product->tracking_type
                    : TrackingType::from((string) $product->tracking_type);

                $trackedTotal = $type === TrackingType::BATCH
                    ? (int) ProductBatch::query()->where('product_id', $product->id)->sum('quantity')
                    : (int) ProductSerial::query()->where('product_id', $product->id)
                        ->where('status', ProductSerial::STATUS_AVAILABLE)->count();

                $recorded = (int) $product->stock;

                return [
                    'product' => $product,
                    'tracking_type' => $type->value,
                    'recorded_stock' => $recorded,
                    'tracked_total' => $trackedTotal,
                    'difference' => $trackedTotal - $recorded,
                ];
            })
            ->filter(fn (array $row): bool => $row['difference'] !== 0)
            ->values();
    }

    /**
     * Correct products.stock to match the tracking records for every drifted
     * product in the organization. Each correction is written through the
     * audited recount adjustment path (row-locked, logged, webhook-fired),
     * never a raw stock overwrite, and is attributed to $actor.
     *
     * @return int the number of products corrected
     */
    public function reconcile(int $organizationId, User $actor): int
    {
        $fixed = 0;

        foreach ($this->discrepancies($organizationId) as $row) {
            StockAdjustment::adjust(
                product: $row['product'],
                quantity: $row['difference'],
                type: 'recount',
                reason: 'Tracked-stock reconciliation',
                notes: sprintf(
                    'Reconciled to %s records: recorded %d, tracked %d.',
                    $row['tracking_type'],
                    $row['recorded_stock'],
                    $row['tracked_total'],
                ),
                actor: $actor,
            );

            $fixed++;
        }

        return $fixed;
    }
}
