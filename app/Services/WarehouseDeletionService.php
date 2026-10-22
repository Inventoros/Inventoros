<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\ProductLocation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class WarehouseDeletionService
{
    public function delete(Warehouse $warehouse, User $actor): void
    {
        abort_unless((int) $warehouse->organization_id === (int) $actor->organization_id, 403);
        app(WarehouseAccessService::class)->authorizeWarehouse($actor, (int) $warehouse->id);

        DB::transaction(function () use ($warehouse): void {
            $locked = Warehouse::whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_default) {
                throw ValidationException::withMessages(['warehouse' => 'Cannot delete the default warehouse. Set another warehouse as default first.']);
            }
            $locations = ProductLocation::withTrashed()->where('organization_id', $locked->organization_id)
                ->where('warehouse_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $ids = $locations->pluck('id');
            $stock = DB::table('product_location_stocks')->whereIn('location_id', $ids)->where('quantity', '<>', 0)->exists();
            // An unbinned product still holds its stock at its primary location.
            $stock = $stock || DB::table('products as p')->whereIn('p.location_id', $ids)->where('p.stock', '<>', 0)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('product_location_stocks as b')->whereColumn('b.product_id', 'p.id'))
                ->whereRaw('NOT (p.has_variants = TRUE AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL))')->exists();
            if (Schema::hasTable('variant_location_stocks')) {
                $stock = $stock || DB::table('variant_location_stocks')->whereIn('location_id', $ids)->where('quantity', '<>', 0)->exists();
                $stock = $stock || DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
                    ->whereIn('p.location_id', $ids)->where('v.stock', '<>', 0)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('variant_location_stocks as b')->whereColumn('b.product_variant_id', 'v.id'))->exists();
            } else {
                $stock = $stock || DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
                    ->whereIn('p.location_id', $ids)->where('v.stock', '<>', 0)->exists();
            }
            if ($stock) {
                throw ValidationException::withMessages(['warehouse' => 'Cannot delete this warehouse while stock remains in its locations. Move or adjust that stock first.']);
            }
            $transfers = DB::table('stock_transfers')->where('organization_id', $locked->organization_id)
                ->whereIn('status', ['pending', 'prepared', 'in_transit'])
                ->where(fn ($q) => $q->whereIn('from_location_id', $ids)->orWhereIn('to_location_id', $ids))->exists();
            $holds = Schema::hasTable('stock_reservations') && DB::table('stock_reservations')->whereIn('location_id', $ids)
                ->whereNull('released_at')->whereNull('consumed_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
            if ($transfers || $holds) {
                throw ValidationException::withMessages(['warehouse' => 'Finish or cancel active transfers and release stock reservations before deleting this warehouse.']);
            }
            // Keep locations and their history usable; deleting an empty warehouse
            // must not require deleting zero-stock products or historical bin rows.
            foreach ($locations as $location) {
                $location->update(['warehouse_id' => null]);
            }
            $locked->users()->detach();
            $locked->delete();
        });
    }
}
