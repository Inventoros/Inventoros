<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $orders = Order::with(['items'])
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                      ->orWhere('customer_name', 'like', "%{$search}%")
                      ->orWhere('customer_email', 'like', "%{$search}%");
                });
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->byStatus($status);
            })
            ->when($request->input('source'), function ($query, $source) {
                $query->bySource($source);
            })
            ->latest('order_date')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['search', 'status', 'source']),
            'statuses' => ['pending', 'processing', 'shipped', 'delivered', 'cancelled'],
            'sources' => ['manual', 'ebay', 'shopify', 'amazon'],
            'pluginComponents' => [
                'header' => get_page_components('orders.index', 'header'),
                'beforeTable' => get_page_components('orders.index', 'before-table'),
                'footer' => get_page_components('orders.index', 'footer'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $products = Product::forOrganization($organizationId)
            ->active()
            ->with(['category', 'location'])
            ->get(['id', 'name', 'sku', 'price', 'stock', 'category_id', 'location_id']);

        return Inertia::render('Orders/Create', [
            'products' => $products,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_address' => 'nullable|string',
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled',
            'order_date' => 'required|date',
            'shipping' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['order_number'] = Order::generateOrderNumber();
        $validated['source'] = 'manual';

        // Calculate order totals
        $subtotal = 0;
        $orderItems = [];

        foreach ($validated['items'] as $item) {
            $product = Product::find($item['product_id']);
            $itemSubtotal = $item['quantity'] * $item['unit_price'];
            $subtotal += $itemSubtotal;

            $orderItems[] = [
                'product_id' => $item['product_id'],
                'product_name' => $product->name,
                'sku' => $product->sku,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal' => $itemSubtotal,
                'tax' => 0,
                'total' => $itemSubtotal,
            ];

            // Reduce product stock
            $product->decrement('stock', $item['quantity']);
        }

        $validated['subtotal'] = $subtotal;
        $validated['tax'] = $validated['tax'] ?? 0;
        $validated['shipping'] = $validated['shipping'] ?? 0;
        $validated['total'] = $subtotal + $validated['tax'] + $validated['shipping'];

        $order = Order::create($validated);
        $order->items()->createMany($orderItems);

        return redirect()->route('orders.index')
            ->with('success', 'Order created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Order $order): Response
    {
        $order->load(['items.product', 'organization']);

        // Ensure user can only view orders from their organization
        if ($order->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        return Inertia::render('Orders/Show', [
            'order' => $order,
            'pluginComponents' => [
                'header' => get_page_components('orders.show', 'header'),
                'sidebar' => get_page_components('orders.show', 'sidebar'),
                'tabs' => get_page_components('orders.show', 'tabs'),
                'footer' => get_page_components('orders.show', 'footer'),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Order $order): Response
    {
        // Ensure user can only edit orders from their organization
        if ($order->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $organizationId = $request->user()->organization_id;

        $products = Product::forOrganization($organizationId)
            ->active()
            ->with(['category', 'location'])
            ->get(['id', 'name', 'sku', 'price', 'stock', 'category_id', 'location_id']);

        $order->load('items');

        return Inertia::render('Orders/Edit', [
            'order' => $order,
            'products' => $products,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Order $order)
    {
        // Ensure user can only update orders from their organization
        if ($order->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_address' => 'nullable|string',
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled',
            'order_date' => 'required|date',
            'shipping' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        // Update order timestamps based on status
        if ($validated['status'] === 'shipped' && !$order->shipped_at) {
            $validated['shipped_at'] = now();
        } elseif ($validated['status'] === 'delivered' && !$order->delivered_at) {
            $validated['delivered_at'] = now();
        }

        $order->update($validated);

        return redirect()->route('orders.index')
            ->with('success', 'Order updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Order $order)
    {
        // Ensure user can only delete orders from their organization
        if ($order->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Restore stock for all items
        foreach ($order->items as $item) {
            if ($item->product) {
                $item->product->increment('stock', $item->quantity);
            }
        }

        $order->delete();

        return redirect()->route('orders.index')
            ->with('success', 'Order deleted successfully.');
    }
}
