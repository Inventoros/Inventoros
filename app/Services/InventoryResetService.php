<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Audited, organization-wide stock write-off. Catalog and transaction records remain. */
final class InventoryResetService
{
    public function preview(User $actor): array
    {
        $this->authorize($actor);
        $org = (int) $actor->organization_id;
        $hash = hash_init('sha256');
        $counts = [];
        foreach ([
            'products' => ['id', 'stock', 'deleted_at'], 'product_variants' => ['id', 'product_id', 'stock', 'deleted_at'],
            'product_location_stocks' => ['id', 'product_id', 'location_id', 'quantity'],
            'variant_location_stocks' => ['id', 'product_variant_id', 'location_id', 'quantity'],
            'product_serials' => ['id', 'product_id', 'status', 'order_item_id'],
            'product_batches' => ['id', 'product_id', 'quantity'],
        ] as $table => $columns) {
            $counts[$table] = 0;
            hash_update($hash, $table);
            foreach (DB::table($table)->where('organization_id', $org)->orderBy('id')->select($columns)->cursor() as $row) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
                $value = $row->stock ?? $row->quantity ?? null;
                if (($value !== null && (int) $value !== 0) || ($row->status ?? null) === ProductSerial::STATUS_AVAILABLE) {
                    $counts[$table]++;
                }
            }
        }
        $blocked = StockReservation::withoutGlobalScopes()->active()->where('organization_id', $org)->exists()
            || DB::table('product_serials')->where('organization_id', $org)->where('status', ProductSerial::STATUS_RESERVED)->exists()
            || DB::table('stock_transfers')->where('organization_id', $org)->whereIn('status', ['pending', 'prepared', 'in_transit'])->exists()
            || DB::table('stock_audits')->where('organization_id', $org)->where('status', 'in_progress')->exists()
            || DB::table('work_orders')->where('organization_id', $org)->where('status', 'in_progress')->exists();

        return ['signature' => hash_final($hash), 'counts' => $counts, 'blocked' => $blocked];
    }

    public function reset(User $actor, string $signature, string $reason): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $signature, $reason): array {
            $org = (int) $actor->organization_id;
            // The same parent-before-variant order used by all stock write paths.
            $products = Product::withTrashed()->forOrganization($org)->orderBy('id')->lockForUpdate()->get();
            ProductVariant::withTrashed()->forOrganization($org)->orderBy('id')->lockForUpdate()->pluck('id');
            ProductSerial::forOrganization($org)->orderBy('id')->lockForUpdate()->pluck('id');
            ProductBatch::forOrganization($org)->orderBy('id')->lockForUpdate()->pluck('id');
            $preview = $this->preview($actor);
            if ($preview['blocked']) {
                throw ValidationException::withMessages(['reset' => 'Finish or cancel transfers, stock counts and production, and release reservations before resetting stock.']);
            }
            if (! hash_equals($preview['signature'], $signature)) {
                throw ValidationException::withMessages(['reset' => 'Stock changed after this preview. Reload the preview before resetting.']);
            }
            foreach ($products as $product) {
                $this->zero($product, $actor, $reason);
                foreach (ProductVariant::withTrashed()->forOrganization($org)->where('product_id', $product->id)->orderBy('id')->get() as $variant) {
                    $this->zero($variant, $actor, $reason);
                }
            }
            ProductSerial::forOrganization($org)->where('status', ProductSerial::STATUS_AVAILABLE)->orderBy('id')->chunkById(200, function ($serials) use ($actor, $reason): void {
                foreach ($serials as $serial) {
                    $serial->update(['status' => ProductSerial::STATUS_WRITTEN_OFF]);
                    $this->log($actor, $serial, $reason, ['status_before' => ProductSerial::STATUS_AVAILABLE, 'status_after' => ProductSerial::STATUS_WRITTEN_OFF]);
                }
            });
            ProductBatch::forOrganization($org)->where('quantity', '<>', 0)->orderBy('id')->chunkById(200, function ($batches) use ($actor, $reason): void {
                foreach ($batches as $batch) {
                    $before = (int) $batch->quantity;
                    $batch->update(['quantity' => 0]);
                    $this->log($actor, $batch, $reason, ['quantity_before' => $before, 'quantity_after' => 0]);
                }
            });
            $this->log($actor, $actor->organization, $reason, $preview['counts']);

            return $preview['counts'];
        });
    }

    private function zero(Product|ProductVariant $target, User $actor, string $reason): void
    {
        $bins = app(ProductLocationStockService::class);
        // Lazy legacy bins must exist before correcting their quantities.
        $bins->ensureBinned($target);
        foreach ($bins->breakdown($target) as $bin) {
            if ((int) $bin->quantity !== 0) {
                $this->adjust($target, -(int) $bin->quantity, $actor, $reason, (int) $bin->location_id);
            }
        }
        $remaining = (int) $target->fresh()->stock;
        if ($remaining !== 0) {
            $this->adjust($target, -$remaining, $actor, $reason, null);
        }
    }

    private function adjust(Product|ProductVariant $target, int $delta, User $actor, string $reason, ?int $locationId): void
    {
        $method = $target instanceof ProductVariant ? 'adjustVariant' : 'adjust';
        StockAdjustment::$method($target, $delta, 'correction', 'Administrator stock reset', $reason, actor: $actor, locationId: $locationId, syncBins: false);
    }

    private function log(User $actor, $subject, string $reason, array $properties): void
    {
        ActivityLog::create([
            'organization_id' => $actor->organization_id, 'user_id' => $actor->id, 'category' => ActivityLog::CATEGORY_AUDIT,
            'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->id, 'action' => 'inventory_reset',
            'description' => $reason, 'properties' => $properties,
        ]);
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->organization_id && $actor->isAdmin() && $actor->hasPermission('manage_stock'), 403);
    }
}
