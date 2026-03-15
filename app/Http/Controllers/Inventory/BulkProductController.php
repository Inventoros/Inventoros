<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for bulk product operations.
 *
 * Handles bulk delete, category update, price adjustment,
 * and export operations on multiple products at once.
 */
class BulkProductController extends Controller
{
    /**
     * Bulk delete selected products.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $organizationId = $request->user()->organization_id;

        $count = Product::forOrganization($organizationId)
            ->whereIn('id', $validated['ids'])
            ->count();

        Product::forOrganization($organizationId)
            ->whereIn('id', $validated['ids'])
            ->delete();

        return redirect()->route('products.index')
            ->with('success', "{$count} product(s) deleted successfully.");
    }

    /**
     * Bulk update category for selected products.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function bulkUpdateCategory(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
            'category_id' => 'required|exists:product_categories,id',
        ]);

        $organizationId = $request->user()->organization_id;

        $count = Product::forOrganization($organizationId)
            ->whereIn('id', $validated['ids'])
            ->update(['category_id' => $validated['category_id']]);

        return redirect()->route('products.index')
            ->with('success', "{$count} product(s) category updated successfully.");
    }

    /**
     * Bulk update price for selected products.
     *
     * Supports percentage (multiply) and fixed (add/subtract) adjustments.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function bulkUpdatePrice(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
            'type' => 'required|in:percentage,fixed',
            'value' => 'required|numeric',
        ]);

        $organizationId = $request->user()->organization_id;

        $products = Product::forOrganization($organizationId)
            ->whereIn('id', $validated['ids'])
            ->get();

        DB::transaction(function () use ($products, $validated) {
            foreach ($products as $product) {
                $currentPrice = (float) $product->price;

                if ($validated['type'] === 'percentage') {
                    $newPrice = $currentPrice * (1 + ($validated['value'] / 100));
                } else {
                    $newPrice = $currentPrice + $validated['value'];
                }

                // Ensure price does not go below zero
                $product->price = max(0, round($newPrice, 2));
                $product->save();
            }
        });

        return redirect()->route('products.index')
            ->with('success', "{$products->count()} product(s) price updated successfully.");
    }

    /**
     * Bulk export selected products as CSV.
     *
     * @param Request $request
     * @return StreamedResponse
     */
    public function bulkExport(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $organizationId = $request->user()->organization_id;

        $products = Product::with(['category', 'location'])
            ->forOrganization($organizationId)
            ->whereIn('id', $validated['ids'])
            ->get();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="products-export-' . now()->format('Y-m-d') . '.csv"',
        ];

        return response()->streamDownload(function () use ($products) {
            $handle = fopen('php://output', 'w');

            // CSV header row
            fputcsv($handle, [
                'ID',
                'SKU',
                'Name',
                'Description',
                'Price',
                'Purchase Price',
                'Currency',
                'Stock',
                'Min Stock',
                'Max Stock',
                'Category',
                'Location',
                'Barcode',
                'Active',
                'Created At',
            ]);

            foreach ($products as $product) {
                fputcsv($handle, [
                    $product->id,
                    $product->sku,
                    $product->name,
                    $product->description,
                    $product->price,
                    $product->purchase_price,
                    $product->currency,
                    $product->stock,
                    $product->min_stock,
                    $product->max_stock,
                    $product->category?->name,
                    $product->location?->name,
                    $product->barcode,
                    $product->is_active ? 'Yes' : 'No',
                    $product->created_at?->toDateTimeString(),
                ]);
            }

            fclose($handle);
        }, 'products-export-' . now()->format('Y-m-d') . '.csv', $headers);
    }
}
