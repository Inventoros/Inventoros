<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\ActivityLog;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Services\ProductService;
use App\Support\PluginQueryGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing products.
 *
 * Handles CRUD operations for products including listing,
 * creating, updating, and deleting product records.
 * Supports product variants, images, and plugin hooks.
 */
class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService,
    ) {}

    /**
     * Display a listing of products.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function index(Request $request): Response
    {
        $organizationId = (int) $request->user()->organization_id;

        $activeWarehouseId = session('active_warehouse_id');

        // Hook: Allow plugins to modify the product query
        $query = Product::with(['category', 'location'])
            ->forOrganization($organizationId)
            ->when($activeWarehouseId, function ($query, $warehouseId) {
                $query->whereHas('location', function ($q) use ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                });
            })
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
            ->latest();

        // Hook: Modify product list query
        $query = apply_filters('product_list_query', $query, $request);

        // Re-assert tenant isolation: a plugin filter must not be able to widen
        // the query past the caller's organization (e.g. via withoutGlobalScope).
        $query = PluginQueryGuard::organizationScoped($query, Product::class, $organizationId);

        $products = $query->paginate(config('limits.pagination.default'))->withQueryString();

        // Hook: Modify products collection before rendering
        $products = apply_filters('product_list_data', $products, $request);

        $categories = ProductCategory::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name']);

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name']);

        $data = [
            'products' => $products,
            'filters' => $request->only(['search', 'category', 'location', 'low_stock']),
            'categories' => $categories,
            'locations' => $locations,
            'pluginComponents' => [
                'header' => get_page_components('products.index', 'header'),
                'beforeTable' => get_page_components('products.index', 'before-table'),
                'footer' => get_page_components('products.index', 'footer'),
            ],
        ];

        // Hook: Modify all data before rendering
        $data = apply_filters('product_list_page_data', $data, $request);

        // Action: Products list viewed
        do_action('product_list_viewed', $products, $request->user());

        return Inertia::render('Products/Index', $data);
    }

    /**
     * Show the form for creating a new product.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function create(Request $request): Response
    {
        $organizationId = (int) $request->user()->organization_id;

        $categories = ProductCategory::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name']);

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name', 'code']);

        $currencies = config('currencies.supported');
        $defaultCurrency = config('currencies.default');

        $productTypes = [
            ['value' => 'standard', 'label' => 'Standard Product'],
            ['value' => 'kit', 'label' => 'Kit (Bundle)'],
            ['value' => 'assembly', 'label' => 'Assembly'],
        ];

        return Inertia::render('Products/Create', [
            'categories' => $categories,
            'locations' => $locations,
            'currencies' => $currencies,
            'defaultCurrency' => $defaultCurrency,
            'productTypes' => $productTypes,
            'pluginComponents' => [
                'header' => get_page_components('products.create', 'header'),
                'beforeForm' => get_page_components('products.create', 'before-form'),
                'afterForm' => get_page_components('products.create', 'after-form'),
            ],
        ]);
    }

    /**
     * Store a newly created product.
     *
     * @param  Request  $request  The incoming HTTP request containing product data
     * @return RedirectResponse
     */
    public function store(StoreProductRequest $request)
    {
        $validated = $request->validated();

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['type'] = $validated['type'] ?? 'standard';
        $validated['tracking_type'] = $validated['tracking_type'] ?? 'none';

        // Hook: Allow plugins to modify validated data before saving
        $validated = apply_filters('product_store_data', $validated, $request);

        // Action: Before product creation
        do_action('product_before_create', $validated, $request);

        $product = $this->productService->create($validated);

        // Action: After product creation. `product_created` now fires from the
        // Product model observer (so it covers all surfaces, not just web), so
        // only the request-lifecycle after-hook remains here.
        do_action('product_after_create', $product, $request);

        // Hook: Modify redirect response
        $response = apply_filters('product_store_response',
            redirect()->route('products.index')->with('success', 'Product created successfully.'),
            $product,
            $request
        );

        return $response;
    }

    /**
     * Display the specified product.
     *
     * @param  Product  $product  The product to display
     */
    public function show(Product $product): Response
    {
        $eagerLoad = ['category', 'location', 'organization', 'options', 'variants', 'batches', 'serials'];

        // Eager-load components for kits and assemblies
        if (in_array($product->type, ['kit', 'assembly'])) {
            $eagerLoad[] = 'components.componentProduct';
        }

        $product->load($eagerLoad);

        // Ensure user can only view products from their organization
        if ($product->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Hook: Modify product before displaying
        $product = apply_filters('product_show_data', $product, auth()->user());

        // Get product activity history
        $activities = ActivityLog::where('subject_type', Product::class)
            ->where('subject_id', $product->id)
            ->with('user:id,name')
            ->latest()
            ->take(20)
            ->get();

        $data = [
            'product' => $product,
            'activities' => $activities,
            'pluginComponents' => [
                'header' => get_page_components('products.show', 'header'),
                'sidebar' => get_page_components('products.show', 'sidebar'),
                'tabs' => get_page_components('products.show', 'tabs'),
                'footer' => get_page_components('products.show', 'footer'),
            ],
        ];

        // Hook: Modify all show page data
        $data = apply_filters('product_show_page_data', $data, $product);

        // Action: Product viewed
        do_action('product_viewed', $product, auth()->user());

        return Inertia::render('Products/Show', $data);
    }

    /**
     * Show the form for editing the specified product.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Product  $product  The product to edit
     */
    public function edit(Request $request, Product $product): Response
    {
        // Ensure user can only edit products from their organization
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $organizationId = (int) $request->user()->organization_id;

        $categories = ProductCategory::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name']);

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name', 'code']);

        // Load options and variants
        $product->load(['options', 'variants']);

        $currencies = config('currencies.supported');
        $defaultCurrency = config('currencies.default');

        $productTypes = [
            ['value' => 'standard', 'label' => 'Standard Product'],
            ['value' => 'kit', 'label' => 'Kit (Bundle)'],
            ['value' => 'assembly', 'label' => 'Assembly'],
        ];

        return Inertia::render('Products/Edit', [
            'product' => $product,
            'categories' => $categories,
            'locations' => $locations,
            'currencies' => $currencies,
            'defaultCurrency' => $defaultCurrency,
            'productTypes' => $productTypes,
            'pluginComponents' => [
                'header' => get_page_components('products.edit', 'header'),
                'beforeForm' => get_page_components('products.edit', 'before-form'),
                'afterForm' => get_page_components('products.edit', 'after-form'),
            ],
        ]);
    }

    /**
     * Update the specified product.
     *
     * @param  Request  $request  The incoming HTTP request containing updated product data
     * @param  Product  $product  The product to update
     * @return RedirectResponse
     */
    public function update(UpdateProductRequest $request, Product $product)
    {
        // Ensure user can only update products from their organization
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validated();

        // Hook: Modify validated data
        $validated = apply_filters('product_update_data', $validated, $product, $request);

        // Action: Before update
        do_action('product_before_update', $product, $validated, $request);

        $this->productService->update($product, $validated);

        // Action: After update
        do_action('product_updated', $product, $request->user());
        do_action('product_after_update', $product, $request);

        return redirect()->route('products.index')
            ->with('success', 'Product updated successfully.');
    }

    /**
     * Duplicate the specified product.
     *
     * Creates a copy of the product with "(Copy)" appended to the name,
     * a new unique SKU, and stock reset to 0.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Product  $product  The product to duplicate
     * @return RedirectResponse
     */
    public function duplicate(Request $request, Product $product)
    {
        // Ensure user can only duplicate products from their organization
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $newProduct = $product->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
        $newProduct->name = $product->name.' (Copy)';
        $newProduct->stock = 0;

        // Generate a unique SKU
        $baseSku = $product->sku.'-COPY';
        $sku = $baseSku;
        $counter = 1;
        while (Product::where('sku', $sku)->exists()) {
            $sku = $baseSku.'-'.$counter;
            $counter++;
        }
        $newProduct->sku = $sku;

        $newProduct->save();

        return redirect()->route('products.show', $newProduct)
            ->with('success', 'Product duplicated successfully.');
    }

    /**
     * Remove the specified product.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Product  $product  The product to delete
     * @return RedirectResponse
     */
    public function destroy(Request $request, Product $product)
    {
        // Ensure user can only delete products from their organization
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Action: Before delete
        do_action('product_before_delete', $product, $request);

        $product->delete();

        // Action: After delete
        do_action('product_deleted', $product, $request->user());
        do_action('product_after_delete', $product, $request);

        return redirect()->route('products.index')
            ->with('success', 'Product deleted successfully.');
    }
}
