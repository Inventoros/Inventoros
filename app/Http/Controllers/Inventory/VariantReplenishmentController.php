<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckApiPermission;
use App\Models\Auth\Organization;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\VariantReplenishmentPolicy;
use App\Services\VariantReplenishmentService;
use App\Services\WarehouseAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class VariantReplenishmentController extends Controller
{
    public function index(Request $request, VariantReplenishmentService $replenishment, WarehouseAccessService $access)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $query = VariantReplenishmentPolicy::where('organization_id', $request->user()->organization_id)
            ->with(['variant.product', 'warehouse', 'location', 'supplier', 'manager']);
        $ids = $access->accessibleWarehouseIds($request->user());
        if ($ids !== null) {
            $query->whereIn('warehouse_id', $ids);
        }
        if (filled($data['search'] ?? null)) {
            $needle = '%'.mb_strtolower($data['search']).'%';
            $query->whereHas('variant', fn ($q) => $q->where(fn ($q) => $q->whereRaw('LOWER(sku) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(title) LIKE ?', [$needle])->orWhereHas('product', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$needle]))));
        }
        $page = $query->orderBy('id')->paginate(20)->withQueryString();
        $forecasts = $replenishment->forecasts($page->getCollection());
        $page->through(function ($policy) use ($forecasts): array {
            $available = $policy->variant && ! $policy->variant->trashed() && $policy->variant->is_active
                && $policy->variant->product?->is_active && $policy->variant->product?->has_variants && ! $policy->variant->product?->isKit()
                && $policy->warehouse && ! $policy->warehouse->trashed() && $policy->warehouse->is_active
                && $policy->location && ! $policy->location->trashed() && $policy->location->is_active
                && (int) $policy->location->warehouse_id === (int) $policy->warehouse_id
                && $policy->supplier && ! $policy->supplier->trashed() && $policy->supplier->is_active
                && strtoupper($policy->supplier->currency ?: Organization::currencyFor((int) $policy->organization_id)) === $policy->currency;

            return $policy->attributesToArray() + ['variant' => $policy->variant?->only(['id', 'sku', 'title']),
                'product_name' => $policy->variant?->product?->name, 'warehouse' => $policy->warehouse?->only(['id', 'name']),
                'location' => $policy->location?->only(['id', 'name']), 'supplier' => $policy->supplier?->only(['id', 'name', 'currency']),
                'manager' => $policy->manager?->only(['id', 'name']), 'available' => $available, 'forecast' => $forecasts[$policy->id]];
        });
        if ($request->is('api/*')) {
            return response()->json($page);
        }

        return Inertia::render('Inventory/VariantReplenishment', ['policies' => $page,
            'filters' => ['search' => $data['search'] ?? ''], 'defaultCurrency' => Organization::currencyFor((int) $request->user()->organization_id)]);
    }

    public function options(Request $request, WarehouseAccessService $access)
    {
        $data = $request->validate(['variant_search' => ['nullable', 'string', 'max:100'], 'supplier_search' => ['nullable', 'string', 'max:100'],
            'location_search' => ['nullable', 'string', 'max:100'], 'warehouse_id' => ['nullable', 'integer']]);
        $org = (int) $request->user()->organization_id;
        $variantNeedle = '%'.mb_strtolower($data['variant_search'] ?? '').'%';
        $variants = ProductVariant::where('organization_id', $org)->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('organization_id', $org)->active()->where('has_variants', true)->where('type', '<>', 'kit'))
            ->where(fn ($q) => $q->whereRaw('LOWER(sku) LIKE ?', [$variantNeedle])->orWhereRaw('LOWER(title) LIKE ?', [$variantNeedle])
                ->orWhereHas('product', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$variantNeedle])))
            ->with('product:id,name')->orderBy('id')->limit(20)->get()->map(fn ($v) => ['id' => $v->id, 'sku' => $v->sku, 'label' => $v->product->name.' / '.$v->title.' / '.$v->sku]);
        $suppliers = Supplier::where('organization_id', $org)->where('is_active', true)
            ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($data['supplier_search'] ?? '').'%'])->orderBy('name')->limit(20)->get(['id', 'name', 'currency']);
        $locations = [];
        if (! empty($data['warehouse_id'])) {
            $access->authorizeWarehouse($request->user(), (int) $data['warehouse_id']);
            $locations = ProductLocation::where('organization_id', $org)->where('warehouse_id', $data['warehouse_id'])->active()
                ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($data['location_search'] ?? '').'%'])
                    ->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower($data['location_search'] ?? '').'%']))
                ->orderBy('name')->limit(25)->get(['id', 'name', 'code']);
        }

        return response()->json(['variants' => $variants, 'suppliers' => $suppliers,
            'warehouses' => $access->accessibleWarehousesQuery($request->user())->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'locations' => $locations]);
    }

    public function store(Request $request, VariantReplenishmentService $replenishment)
    {
        $this->authorizeAutomationToken($request);
        $policy = $replenishment->save($request->user(), $request->validate(VariantReplenishmentService::rules()));

        return $request->is('api/*') ? response()->json(['data' => $policy], 201) : redirect()->route('variant-replenishment.index');
    }

    public function update(Request $request, VariantReplenishmentPolicy $policy, VariantReplenishmentService $replenishment)
    {
        $this->authorizeAutomationToken($request);
        $policy = $replenishment->save($request->user(), $request->validate(VariantReplenishmentService::rules()), $policy);

        return $request->is('api/*') ? response()->json(['data' => $policy]) : redirect()->route('variant-replenishment.index');
    }

    public function destroy(Request $request, VariantReplenishmentPolicy $policy, VariantReplenishmentService $replenishment)
    {
        $replenishment->remove($request->user(), $policy);

        return $request->is('api/*') ? response()->json(['deleted' => true]) : redirect()->route('variant-replenishment.index');
    }

    public function draft(Request $request, VariantReplenishmentPolicy $policy, VariantReplenishmentService $replenishment)
    {
        $po = $replenishment->createDraft($request->user(), $policy);
        if ($request->is('api/*')) {
            return response()->json(['created' => $po !== null, 'data' => $po?->only(['id', 'po_number', 'status', 'currency', 'total'])]);
        }

        return $po ? redirect()->route('purchase-orders.show', $po) : redirect()->route('variant-replenishment.index')->with('info', 'No replenishment is currently needed.');
    }

    private function authorizeAutomationToken(Request $request): void
    {
        if ($request->boolean('auto_reorder')) {
            abort_unless(CheckApiPermission::tokenAllows($request->user()->currentAccessToken())('create_purchase_orders'), 403);
        }
    }
}
