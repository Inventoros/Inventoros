<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Search across multiple models and return categorized results.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q', ''));
        $organizationId = $request->user()->organization_id;
        $limit = 5;

        if ($query === '') {
            return response()->json([
                'products' => [],
                'orders' => [],
                'customers' => [],
                'suppliers' => [],
                'purchase_orders' => [],
            ]);
        }

        // A product matches on its own name/SKU/barcode or on one of its
        // variants' SKU/barcode; a variant match is named in the subtitle.
        $variantMatch = fn ($variants) => Search::apply($variants, ['sku', 'barcode'], $query);

        $products = Product::where('organization_id', $organizationId)
            ->where(function ($q) use ($query, $variantMatch) {
                Search::apply($q, ['name', 'sku', 'barcode'], $query)
                    ->orWhereHas('variants', $variantMatch);
            })
            ->with(['variants' => fn ($relation) => $variantMatch($relation->getQuery())])
            ->limit($limit)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'title' => $product->name,
                'subtitle' => $this->productSubtitle($product),
                'url' => route('products.show', $product->id),
                'type' => 'product',
                'icon' => 'product',
            ]);

        $orders = Search::apply(
            Order::where('organization_id', $organizationId),
            ['order_number', 'customer_name'],
            $query,
        )
            ->limit($limit)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'title' => $order->order_number,
                'subtitle' => $order->customer_name ?? 'No customer',
                'url' => route('orders.show', $order->id),
                'type' => 'order',
                'icon' => 'order',
            ]);

        $customers = Search::apply(
            Customer::where('organization_id', $organizationId),
            ['name', 'email'],
            $query,
        )
            ->limit($limit)
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'title' => $customer->name,
                'subtitle' => $customer->email ?? '',
                'url' => route('customers.show', $customer->id),
                'type' => 'customer',
                'icon' => 'customer',
            ]);

        $suppliers = Search::apply(
            Supplier::where('organization_id', $organizationId),
            ['name', 'email'],
            $query,
        )
            ->limit($limit)
            ->get()
            ->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'title' => $supplier->name,
                'subtitle' => $supplier->email ?? '',
                'url' => route('suppliers.show', $supplier->id),
                'type' => 'supplier',
                'icon' => 'supplier',
            ]);

        $purchaseOrders = Search::apply(
            PurchaseOrder::where('organization_id', $organizationId),
            ['po_number'],
            $query,
        )
            ->limit($limit)
            ->get()
            ->map(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'title' => $po->po_number,
                'subtitle' => $po->status_label,
                'url' => route('purchase-orders.show', $po->id),
                'type' => 'purchase_order',
                'icon' => 'purchase_order',
            ]);

        return response()->json([
            'products' => $products->values(),
            'orders' => $orders->values(),
            'customers' => $customers->values(),
            'suppliers' => $suppliers->values(),
            'purchase_orders' => $purchaseOrders->values(),
        ]);
    }

    /**
     * The product's SKU, plus the variant that matched when the hit came from
     * a variant code rather than the product's own fields.
     */
    private function productSubtitle(Product $product): string
    {
        $sku = $product->sku ?? 'No SKU';
        $variant = $product->variants->first();

        if ($variant === null) {
            return $sku;
        }

        $label = $variant->title ?: 'Variant';
        $code = $variant->sku ?: $variant->barcode;

        return "{$sku} · {$label} ({$code})";
    }
}
