<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Shared, permission-scoped stock rows for the warehouse screen, exports and clients. */
final class StockBalanceService
{
    public const SORTS = ['sku', 'product_name', 'variant_title', 'warehouse_name', 'location_name', 'on_hand', 'reserved', 'available', 'in_transit'];

    public function __construct(private readonly WarehouseAccessService $access) {}

    public function query(User $viewer, array $filters = []): Builder
    {
        $organizationId = (int) $viewer->organization_id;
        $productIds = isset($filters['product_ids']) ? array_values(array_unique(array_map('intval', $filters['product_ids']))) : null;
        $targets = $this->targets($organizationId, $productIds);
        $bins = $this->bins($organizationId, $productIds);
        $assigned = DB::query()->fromSub(clone $bins, 's')->groupBy('product_id', 'product_variant_id')
            ->selectRaw('product_id, product_variant_id, SUM(quantity) as assigned');

        $unassigned = DB::query()->fromSub(clone $targets, 't')
            ->leftJoinSub($assigned, 'a', fn (JoinClause $join) => $this->sameTarget($join, 'a', 't'))
            ->where(fn ($q) => $q->whereRaw('t.stock <> COALESCE(a.assigned, 0)')->orWhereNull('a.product_id'))
            ->selectRaw('t.product_id, t.product_variant_id, CASE WHEN a.product_id IS NULL THEN t.primary_location_id ELSE NULL END as location_id, t.stock - COALESCE(a.assigned, 0) as quantity, 0 as incoming');

        $incoming = DB::table('stock_transfer_items as i')->join('stock_transfers as st', 'st.id', '=', 'i.stock_transfer_id')
            ->where('st.organization_id', $organizationId)->where('st.status', 'in_transit')
            ->when($productIds !== null, fn ($q) => $q->whereIn('i.product_id', $productIds))
            ->groupBy('i.product_id', 'i.product_variant_id', 'st.to_location_id')
            ->selectRaw('i.product_id, i.product_variant_id, st.to_location_id as location_id, 0 as quantity, SUM(i.quantity) as incoming');

        $quantities = DB::query()->fromSub($bins->unionAll($unassigned)->unionAll($incoming), 'q')
            ->groupBy('product_id', 'product_variant_id', 'location_id')
            ->selectRaw('product_id, product_variant_id, location_id, SUM(quantity) as on_hand, SUM(incoming) as in_transit');

        $holds = StockReservation::withoutGlobalScopes()->active()->where('organization_id', $organizationId)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))->toBase();
        $binHolds = (clone $holds)->selectRaw('product_id, product_variant_id, location_id, SUM(quantity) as held')
            ->groupBy('product_id', 'product_variant_id', 'location_id');
        $totalHolds = (clone $holds)->selectRaw('product_id, product_variant_id, SUM(quantity) as held')
            ->groupBy('product_id', 'product_variant_id');

        $free = '(q.on_hand - COALESCE(h.held, 0))';
        $totalFree = '(t.stock - COALESCE(th.held, 0))';
        $available = "CASE WHEN t.active = 0 OR {$free} < 0 OR {$totalFree} < 0 THEN 0 WHEN {$free} < {$totalFree} THEN {$free} ELSE {$totalFree} END";
        $rows = DB::query()->fromSub($quantities, 'q')
            ->joinSub($targets, 't', fn (JoinClause $join) => $this->sameTarget($join, 'q', 't'))
            ->leftJoin('product_locations as l', 'l.id', '=', 'q.location_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->leftJoinSub($binHolds, 'h', function (JoinClause $join): void {
                $this->sameTarget($join, 'q', 'h');
                $join->whereRaw('COALESCE(q.location_id, 0) = COALESCE(h.location_id, 0)');
            })
            ->leftJoinSub($totalHolds, 'th', fn (JoinClause $join) => $this->sameTarget($join, 'q', 'th'))
            ->where(fn ($q) => $q->whereNull('q.location_id')->orWhere(fn ($q) => $q->where('l.organization_id', $organizationId)->whereNull('l.deleted_at')))
            ->whereNull('w.deleted_at')
            ->selectRaw('t.product_id, t.product_variant_id, t.sku, t.product_name, t.variant_title, t.min_stock, q.location_id, l.name as location_name, w.id as warehouse_id, w.name as warehouse_name, q.on_hand, COALESCE(h.held, 0) as reserved, q.in_transit, '.$available.' as available');

        $query = DB::query()->fromSub($rows, 'balances');
        $warehouseIds = $this->access->accessibleWarehouseIds($viewer);
        if ($warehouseIds !== null) {
            $query->whereIn('warehouse_id', $warehouseIds);
        }
        if (! empty($filters['warehouse_id'])) {
            $warehouse = Warehouse::forOrganization($organizationId)->findOrFail((int) $filters['warehouse_id']);
            $this->access->authorizeWarehouse($viewer, (int) $warehouse->id);
            $query->where('warehouse_id', $warehouse->id);
        }
        if (! empty($filters['location_id'])) {
            $location = ProductLocation::forOrganization($organizationId)->findOrFail((int) $filters['location_id']);
            $this->access->authorizeLocation($viewer, $location);
            $query->where('location_id', $location->id);
        }
        if (! empty($filters['search'])) {
            $search = '%'.mb_substr((string) $filters['search'], 0, 255).'%';
            $query->where(fn ($q) => $q->where('sku', 'like', $search)->orWhere('product_name', 'like', $search)->orWhere('variant_title', 'like', $search));
        }
        if (filter_var($filters['out_of_stock'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where('available', '=', 0);
        }
        if (! filter_var($filters['include_zero'] ?? false, FILTER_VALIDATE_BOOLEAN) && ! filter_var($filters['out_of_stock'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where(fn ($q) => $q->where('on_hand', '<>', 0)->orWhere('reserved', '>', 0)->orWhere('in_transit', '>', 0));
        }

        $sort = in_array($filters['sort_by'] ?? '', self::SORTS, true) ? $filters['sort_by'] : 'sku';
        $direction = ($filters['sort_dir'] ?? '') === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sort, $direction)->orderBy('product_id')->orderBy('product_variant_id')->orderBy('location_id');
    }

    public function paginate(User $viewer, array $filters = []): LengthAwarePaginator
    {
        return $this->query($viewer, $filters)
            ->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 25))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)))
            ->withQueryString()->through(fn ($row) => $this->normalise($row));
    }

    public function normalise(object $row): array
    {
        $row = (array) $row;
        foreach (['product_id', 'product_variant_id', 'location_id', 'warehouse_id', 'on_hand', 'reserved', 'available', 'in_transit', 'min_stock'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    private function targets(int $organizationId, ?array $productIds = null): Builder
    {
        $plain = DB::table('products as p')->where('p.organization_id', $organizationId)->whereNull('p.deleted_at')
            ->when($productIds !== null, fn ($q) => $q->whereIn('p.id', $productIds))
            ->whereRaw('NOT (p.has_variants = TRUE AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL))')
            ->selectRaw("p.id as product_id, NULL as product_variant_id, p.sku, p.name as product_name, '' as variant_title, p.stock, p.location_id as primary_location_id, COALESCE(p.min_stock, 0) as min_stock, CASE WHEN p.is_active THEN 1 ELSE 0 END as active");
        $variants = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.organization_id', $organizationId)->where('v.organization_id', $organizationId)
            ->when($productIds !== null, fn ($q) => $q->whereIn('p.id', $productIds))
            ->where('p.has_variants', true)->whereNull('p.deleted_at')->whereNull('v.deleted_at')
            ->selectRaw('p.id as product_id, v.id as product_variant_id, COALESCE(NULLIF(v.sku, \'\'), p.sku) as sku, p.name as product_name, COALESCE(v.title, \'\') as variant_title, v.stock, p.location_id as primary_location_id, COALESCE(v.min_stock, p.min_stock, 0) as min_stock, CASE WHEN p.is_active AND v.is_active THEN 1 ELSE 0 END as active');

        return $plain->unionAll($variants);
    }

    private function bins(int $organizationId, ?array $productIds = null): Builder
    {
        $plain = DB::table('product_location_stocks')->where('organization_id', $organizationId)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->selectRaw('product_id, NULL as product_variant_id, location_id, quantity, 0 as incoming');
        $variants = DB::table('variant_location_stocks as b')->join('product_variants as v', 'v.id', '=', 'b.product_variant_id')
            ->where('b.organization_id', $organizationId)->where('v.organization_id', $organizationId)
            ->when($productIds !== null, fn ($q) => $q->whereIn('v.product_id', $productIds))
            ->selectRaw('v.product_id, v.id as product_variant_id, b.location_id, b.quantity, 0 as incoming');

        return $plain->unionAll($variants);
    }

    private function sameTarget(JoinClause $join, string $left, string $right): void
    {
        $join->on($left.'.product_id', '=', $right.'.product_id')
            ->whereRaw("COALESCE({$left}.product_variant_id, 0) = COALESCE({$right}.product_variant_id, 0)");
    }
}
