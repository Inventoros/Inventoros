<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RunInventoryReset;
use App\Models\ActivityLog;
use App\Models\Inventory\InventoryResetRun;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockReservation;
use App\Models\Inventory\VariantLocationStock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Atomic, audited stock write-off; catalogue and transaction records remain. */
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
            foreach (DB::table($table)->where('organization_id', $org)->select($columns)->lazyById(500) as $row) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
                $value = $row->stock ?? $row->quantity ?? null;
                if (($value !== null && (int) $value !== 0) || ($row->status ?? null) === ProductSerial::STATUS_AVAILABLE) {
                    $counts[$table]++;
                }
            }
        }
        $blockers = [
            'reservations' => StockReservation::withoutGlobalScopes()->active()->where('organization_id', $org)->count(),
            'serials' => DB::table('product_serials')->where('organization_id', $org)->where('status', ProductSerial::STATUS_RESERVED)->count(),
            'transfers' => DB::table('stock_transfers')->where('organization_id', $org)->whereIn('status', ['pending', 'prepared', 'in_transit'])->count(),
            'audits' => DB::table('stock_audits')->where('organization_id', $org)->whereNull('deleted_at')->where('status', 'in_progress')->count(),
            'production' => DB::table('work_orders')->where('organization_id', $org)->where('status', 'in_progress')->count(),
            'integrity' => $this->hasOwnershipMismatch($org),
            'pending' => InventoryResetRun::where('organization_id', $org)->whereIn('status', ['pending', 'running'])->exists(),
            'limit' => array_sum($counts) > $this->limit(),
        ];

        return ['signature' => hash_final($hash), 'counts' => $counts, 'blockers' => $blockers,
            'blocked' => (bool) array_filter($blockers), 'limit' => $this->limit()];
    }

    public function queue(User $actor, string $signature, string $reason): InventoryResetRun
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $signature, $reason): InventoryResetRun {
            InventoryWorkflowLock::acquire((int) $actor->organization_id);
            $preview = $this->preview($actor);
            $this->assertPreview($preview, $signature);
            $run = InventoryResetRun::create([
                'public_id' => (string) Str::uuid(), 'organization_id' => $actor->organization_id, 'actor_id' => $actor->id,
                'status' => 'pending', 'signature' => $signature, 'reason' => $reason, 'counts' => $preview['counts'],
            ]);
            // The job and operation use the same database transaction. A
            // failed enqueue cannot leave a pending operation with no job.
            RunInventoryReset::dispatch($run->id)->onConnection('inventory-resets')
                ->onQueue('inventory-resets')->beforeCommit();

            return $run;
        });
    }

    public function reset(User $actor, string $signature, string $reason, ?InventoryResetRun $run = null): array
    {
        $this->authorize($actor);
        abort_if($run !== null && (int) $run->organization_id !== (int) $actor->organization_id, 403);

        return DB::transaction(function () use ($actor, $signature, $reason, $run): array {
            $org = (int) $actor->organization_id;
            InventoryWorkflowLock::acquire($org);
            // Parent-before-variant-before-tracked-unit order matches stock
            // writers. Keep locks, not entire catalogues, in PHP memory.
            foreach (['products', 'product_variants', 'product_serials', 'product_batches'] as $table) {
                DB::table($table)->where('organization_id', $org)->select('id')->lockForUpdate()
                    ->chunkById(500, static function (): void {});
            }
            $preview = $this->preview($actor);
            if ($run !== null) {
                $preview['blockers']['pending'] = false;
            }
            $this->assertPreview($preview, $signature);
            $operationId = $run?->public_id ?? (string) Str::uuid();

            foreach ([Product::class, ProductVariant::class] as $model) {
                $variant = $model === ProductVariant::class;
                $table = $variant ? 'variant_location_stocks' : 'product_location_stocks';
                $column = $variant ? 'product_variant_id' : 'product_id';
                $targets = $model::withTrashed()->forOrganization($org)->where(function ($query) use ($table, $column, $model, $org): void {
                    $query->where('stock', '<>', 0)->orWhereExists(function ($bins) use ($table, $column, $model, $org): void {
                        $bins->selectRaw('1')->from($table)->where('organization_id', $org)
                            ->whereColumn($column, (new $model)->getTable().'.id')->where('quantity', '<>', 0);
                    });
                });
                foreach ($targets->lazyById(200) as $target) {
                    $this->zero($target, $actor, $reason, $run);
                }
            }
            foreach (ProductSerial::forOrganization($org)->where('status', ProductSerial::STATUS_AVAILABLE)->lazyById(200) as $serial) {
                $serial->update(['status' => ProductSerial::STATUS_WRITTEN_OFF]);
                $this->log($actor, $serial, $reason, ['status' => ProductSerial::STATUS_AVAILABLE], ['status' => ProductSerial::STATUS_WRITTEN_OFF], $operationId);
            }
            foreach (ProductBatch::forOrganization($org)->where('quantity', '<>', 0)->lazyById(200) as $batch) {
                $before = (int) $batch->quantity;
                $batch->update(['quantity' => 0]);
                $this->log($actor, $batch, $reason, ['quantity' => $before], ['quantity' => 0], $operationId);
            }
            $this->log($actor, $actor->organization, $reason, [], $preview['counts'] + ['status' => 'completed'], $operationId);

            return $preview['counts'];
        }, 3);
    }

    private function assertPreview(array $preview, string $signature): void
    {
        foreach (['integrity', 'limit', 'pending'] as $reason) {
            if ($preview['blockers'][$reason]) {
                throw ValidationException::withMessages(['reset' => 'stockReset.errors.'.$reason]);
            }
        }
        if (array_sum(array_intersect_key($preview['blockers'], array_flip(['reservations', 'serials', 'transfers', 'audits', 'production']))) > 0) {
            throw ValidationException::withMessages(['reset' => 'stockReset.errors.blocked']);
        }
        if (! hash_equals($preview['signature'], $signature)) {
            throw ValidationException::withMessages(['reset' => 'stockReset.errors.changed']);
        }
    }

    private function zero(Product|ProductVariant $target, User $actor, string $reason, ?InventoryResetRun $run): void
    {
        $bins = app(ProductLocationStockService::class);
        if ((int) $target->stock !== 0) {
            $bins->ensureBinned($target);
        }
        $model = $target instanceof ProductVariant ? VariantLocationStock::class : ProductLocationStock::class;
        $column = $target instanceof ProductVariant ? 'product_variant_id' : 'product_id';
        foreach ($model::where('organization_id', $actor->organization_id)->where($column, $target->id)->where('quantity', '<>', 0)->orderBy('id')->get() as $bin) {
            if ($target instanceof ProductVariant) {
                $bin->setRelation('variant', $target);
            }
            $adjustment = $this->adjust($target, -(int) $bin->quantity, $actor, $reason, $run);
            $bin->update(['quantity' => 0]);
            $adjustment->update(['location_id' => $bin->location_id]);
        }
        $remaining = (int) $target->fresh()->stock;
        if ($remaining !== 0) {
            $this->adjust($target, -$remaining, $actor, $reason, $run);
        }
    }

    private function adjust(Product|ProductVariant $target, int $delta, User $actor, string $reason, ?InventoryResetRun $run): StockAdjustment
    {
        $method = $target instanceof ProductVariant ? 'adjustVariant' : 'adjust';

        return StockAdjustment::$method($target, $delta, 'correction', 'Administrator stock reset', $reason,
            reference: $run, allowNegative: true, actor: $actor, syncBins: false);
    }

    private function log(User $actor, $subject, string $reason, array $before, array $after, string $operationId): void
    {
        ActivityLog::create([
            'organization_id' => $actor->organization_id, 'user_id' => $actor->id, 'category' => ActivityLog::CATEGORY_AUDIT,
            'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->id, 'action' => 'inventory_reset',
            'description' => $reason, 'properties' => ['old' => $before, 'new' => $after + ['operation' => $operationId]],
        ]);
    }

    private function hasOwnershipMismatch(int $org): bool
    {
        foreach ([
            ['product_variants', 'product_id', 'products'], ['product_location_stocks', 'product_id', 'products'],
            ['variant_location_stocks', 'product_variant_id', 'product_variants'],
            ['product_serials', 'product_id', 'products'], ['product_batches', 'product_id', 'products'],
        ] as [$table, $column, $parent]) {
            if (DB::table($table.' as child')->where('child.organization_id', $org)->whereNotExists(function ($query) use ($column, $parent): void {
                $query->selectRaw('1')->from($parent.' as parent')->whereColumn('parent.id', 'child.'.$column)
                    ->whereColumn('parent.organization_id', 'child.organization_id');
            })->exists()) {
                return true;
            }
        }
        foreach (['products', 'product_location_stocks', 'variant_location_stocks'] as $table) {
            if (DB::table($table.' as child')->where('child.organization_id', $org)->whereNotNull('child.location_id')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('product_locations as parent')
                    ->whereColumn('parent.id', 'child.location_id')->whereColumn('parent.organization_id', 'child.organization_id'))->exists()) {
                return true;
            }
        }

        return false;
    }

    private function limit(): int
    {
        return max(1, (int) config('inventory.stock_reset_max_records', 5000));
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->organization_id && $actor->isAdmin() && $actor->hasPermission('manage_stock'), 403);
    }
}
