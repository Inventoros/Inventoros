<?php

namespace App\Http\Controllers;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $products = Product::with(['category', 'location'])
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('sku', 'like', "%{$search}%")
                      ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->when($request->input('category'), function ($query, $category) {
                $query->where('category_id', $category);
            })
            ->when($request->input('location'), function ($query, $location) {
                $query->where('location_id', $location);
            })
            ->when($request->input('low_stock'), function ($query) {
                $query->lowStock();
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => $request->only(['search', 'category', 'location', 'low_stock']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $categories = ProductCategory::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name']);

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name', 'code']);

        return Inertia::render('Products/Create', [
            'categories' => $categories,
            'locations' => $locations,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'sku' => 'required|string|max:255|unique:products,sku',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'min_stock' => 'required|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'barcode' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:product_categories,id',
            'location_id' => 'nullable|exists:product_locations,id',
            'is_active' => 'boolean',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;

        Product::create($validated);

        return redirect()->route('products.index')
            ->with('success', 'Product created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product): Response
    {
        $product->load(['category', 'location', 'organization']);

        // Ensure user can only view products from their organization
        if ($product->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        return Inertia::render('Products/Show', [
            'product' => $product,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Product $product): Response
    {
        // Ensure user can only edit products from their organization
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $organizationId = $request->user()->organization_id;

        $categories = ProductCategory::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name']);

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name', 'code']);

        return Inertia::render('Products/Edit', [
            'product' => $product,
            'categories' => $categories,
            'locations' => $locations,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        // Ensure user can only update products from their organization
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'sku' => 'required|string|max:255|unique:products,sku,' . $product->id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'min_stock' => 'required|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'barcode' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:product_categories,id',
            'location_id' => 'nullable|exists:product_locations,id',
            'is_active' => 'boolean',
        ]);

        $product->update($validated);

        return redirect()->route('products.index')
            ->with('success', 'Product updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Product $product)
    {
        // Ensure user can only delete products from their organization
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $product->delete();

        return redirect()->route('products.index')
            ->with('success', 'Product deleted successfully.');
    }
}
