<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Support\SpreadsheetSafety;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
     * @return RedirectResponse
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
     * @return RedirectResponse
     */
    public function bulkUpdateCategory(Request $request)
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
            // Org-scope the category like every other FK reference, so a user
            // can't point their products at another organization's category
            // (which then leaks that category's name into their lists/exports).
            'category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')->where('organization_id', $organizationId)],
        ]);

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
     * @return RedirectResponse
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
            'Content-Disposition' => 'attachment; filename="products-export-'.now()->format('Y-m-d').'.csv"',
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
            ], escape: '');

            foreach ($products as $product) {
                fputcsv($handle, SpreadsheetSafety::neutraliseRow([
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
                ]), escape: '');
            }

            fclose($handle);
        }, 'products-export-'.now()->format('Y-m-d').'.csv', $headers);
    }
}
