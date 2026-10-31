<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class StockAuditReadService
{
    public const RELATIONS = ['product:id,organization_id,name,sku,barcode,has_variants',
        'variant:id,organization_id,product_id,title,sku,barcode', 'location:id,name,code', 'countedByUser:id,name'];

    public static function pendingReviewSql(): string
    {
        return "(review_status = 'recount_requested' OR (counted_quantity IS NOT NULL AND counted_quantity <> system_quantity AND (review_status <> 'approved' OR review_revision IS NULL)))";
    }

    public function summary(StockAudit $audit): array
    {
        $row = DB::table('stock_audit_items')->where('stock_audit_id', $audit->id)
            ->selectRaw('COUNT(*) AS total_items, COALESCE(SUM(CASE WHEN counted_quantity IS NOT NULL THEN 1 ELSE 0 END), 0) AS counted_items,
                COALESCE(SUM(CASE WHEN counted_quantity IS NOT NULL AND counted_quantity <> system_quantity THEN 1 ELSE 0 END), 0) AS discrepancies,
                COALESCE(SUM(CASE WHEN '.self::pendingReviewSql().' THEN 1 ELSE 0 END), 0) AS review_required')->first();
        $total = (int) $row->total_items;
        $counted = (int) $row->counted_items;

        return ['total_items' => $total, 'counted_items' => $counted, 'discrepancies' => (int) $row->discrepancies,
            'review_required' => $audit->require_variance_approval && $audit->status === 'in_progress' ? (int) $row->review_required : 0,
            'progress' => $total > 0 ? round($counted / $total * 100) : 0];
    }

    public function filters(array $data): array
    {
        return ['search' => trim($data['search'] ?? ''), 'item_status' => $data['item_status'] ?? 'all',
            'per_page' => (int) ($data['per_page'] ?? 25), 'page' => (int) ($data['page'] ?? 1)];
    }

    public function paginate(StockAudit $audit, array $data): LengthAwarePaginator
    {
        $filters = $this->filters($data);
        $query = StockAuditItem::where('stock_audit_id', $audit->id)->with(self::RELATIONS);
        if ($filters['search'] !== '') {
            $needle = '%'.mb_strtolower($filters['search']).'%';
            $query->where(fn ($q) => $q->whereHas('product', fn ($p) => $p->where('organization_id', $audit->organization_id)
                ->where(fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$needle])->orWhereRaw('LOWER(sku) LIKE ?', [$needle])->orWhereRaw('LOWER(barcode) LIKE ?', [$needle])))
                ->orWhereHas('variant', fn ($v) => $v->where('organization_id', $audit->organization_id)
                    ->where(fn ($v) => $v->whereRaw('LOWER(title) LIKE ?', [$needle])->orWhereRaw('LOWER(sku) LIKE ?', [$needle])->orWhereRaw('LOWER(barcode) LIKE ?', [$needle]))));
        }
        match ($filters['item_status']) {
            'pending' => $query->whereNull('counted_quantity'),
            'counted' => $query->whereNotNull('counted_quantity'),
            'variance' => $query->whereNotNull('counted_quantity')->whereColumn('counted_quantity', '<>', 'system_quantity'),
            'review' => $audit->require_variance_approval && $audit->status === 'in_progress'
                ? $query->whereRaw(self::pendingReviewSql()) : $query->whereRaw('1 = 0'),
            default => null,
        };

        return $query->orderBy('id')->paginate($filters['per_page'], ['*'], 'page', $filters['page'])->withQueryString();
    }

    public function present(StockAuditItem $item): array
    {
        $item->loadMissing(self::RELATIONS);

        // Do not serialize ProductResource/appended catalogue attributes: a
        // count line only needs labels, not stock calculations per product.
        return $item->attributesToArray() + ['count_revision' => $item->count_revision,
            'product' => $item->product?->only(['id', 'name', 'sku', 'barcode', 'has_variants']),
            'variant' => $item->variant?->only(['id', 'product_id', 'title', 'sku', 'barcode']),
            'location' => $item->location?->only(['id', 'name', 'code']),
            'counted_by_user' => $item->countedByUser?->only(['id', 'name'])];
    }

    public function locations(StockAudit $audit): array
    {
        return DB::table('product_locations')->where('organization_id', $audit->organization_id)
            ->whereIn('id', DB::table('stock_audit_items')->where('stock_audit_id', $audit->id)->select('location_id'))
            ->orderBy('name')->get(['id', 'name', 'code'])->all();
    }

    public function lookup(StockAudit $audit, string $code, ?int $locationId, int $perPage): array
    {
        $code = trim($code);
        $query = fn () => StockAuditItem::where('stock_audit_id', $audit->id)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId));
        $needle = mb_strtolower($code);
        $matches = $query()->where(fn ($q) => $q->whereHas('product', fn ($p) => $p->where('organization_id', $audit->organization_id)
            ->where(fn ($p) => $p->whereRaw('LOWER(sku) = ?', [$needle])->orWhereRaw('LOWER(barcode) = ?', [$needle])))
            ->orWhereHas('variant', fn ($v) => $v->where('organization_id', $audit->organization_id)
                ->where(fn ($v) => $v->whereRaw('LOWER(sku) = ?', [$needle])->orWhereRaw('LOWER(barcode) = ?', [$needle]))))
            ->orderBy('id')->limit(2)->get();
        if ($matches->isEmpty()) {
            // Retain this installation's product QR links and location QR
            // formats. Every resolved record must still belong to this audit.
            $resolved = app(ScanLookupService::class)->resolve((int) $audit->organization_id, $code);
            if (($resolved['type'] ?? null) === 'location') {
                $location = $resolved['location'];
                $inAudit = StockAuditItem::where('stock_audit_id', $audit->id)->where('location_id', $location->id)->exists();

                return ['found' => $inAudit, 'data' => [], 'ambiguous' => false,
                    'location' => $inAudit ? $location->only(['id', 'name', 'code']) : null];
            }
            if (($resolved['type'] ?? null) === 'product') {
                $matches = $query()->where('product_id', $resolved['product']->id)
                    ->when($resolved['variant'] !== null, fn ($q) => $q->where('product_variant_id', $resolved['variant']->id))
                    ->orderBy('id')->limit(2)->get();
            }
        }
        $page = $matches->count() === 1 ? (int) ceil((StockAuditItem::where('stock_audit_id', $audit->id)
            ->where('id', '<=', $matches->first()->id)->count()) / $perPage) : null;

        return ['found' => $matches->isNotEmpty(), 'ambiguous' => $matches->count() > 1,
            'data' => $matches->map(fn ($item) => $this->present($item))->all(), 'page' => $page];
    }
}
