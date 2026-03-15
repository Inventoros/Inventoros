<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
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

        $term = '%' . $query . '%';

        $products = Product::where('organization_id', $organizationId)
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('barcode', 'like', $term);
            })
            ->limit($limit)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'title' => $product->name,
                'subtitle' => $product->sku ?? 'No SKU',
                'url' => route('products.show', $product->id),
                'type' => 'product',
                'icon' => 'product',
            ]);

        $orders = Order::where('organization_id', $organizationId)
            ->where(function ($q) use ($term) {
                $q->where('order_number', 'like', $term)
                    ->orWhere('customer_name', 'like', $term);
            })
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

        $customers = Customer::where('organization_id', $organizationId)
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            })
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

        $suppliers = Supplier::where('organization_id', $organizationId)
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            })
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

        $purchaseOrders = PurchaseOrder::where('organization_id', $organizationId)
            ->where(function ($q) use ($term) {
                $q->where('po_number', 'like', $term);
            })
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
}
