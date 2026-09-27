<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\UpdateWarehouseStockLevelsRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\WarehouseReorderPoint;
use App\Services\WarehouseAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Saves a product's per-warehouse stock thresholds from the product page.
 */
class ProductWarehouseLevelController extends Controller
{
    public function update(UpdateWarehouseStockLevelsRequest $request, Product $product, WarehouseAccessService $access): RedirectResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $levels = $request->validated()['levels'];

        // Every warehouse in the submission must be one the user can access.
        foreach ($levels as $level) {
            $access->authorizeWarehouse($request->user(), (int) $level['warehouse_id']);
        }

        DB::transaction(function () use ($levels, $product) {
            foreach ($levels as $level) {
                $values = [];
                foreach (WarehouseReorderPoint::LEVELS as $field) {
                    $values[$field] = isset($level[$field]) && $level[$field] !== '' ? (int) $level[$field] : null;
                }

                $match = ['product_id' => $product->id, 'warehouse_id' => (int) $level['warehouse_id']];

                // All blank: stop tracking this warehouse separately.
                if (array_filter($values, fn ($value) => $value !== null) === []) {
                    WarehouseReorderPoint::query()->where($match)->delete();

                    continue;
                }

                WarehouseReorderPoint::updateOrCreate($match, $values + ['organization_id' => $product->organization_id]);
            }
        });

        return redirect()->back()->with('success', 'Warehouse stock levels saved.');
    }
}
