<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Enums\BarcodeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductSupplier;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\SupplierPriceHistory;
use App\Models\Inventory\VariantLocationStock;
use App\Services\ExchangeRates\OrganizationCurrencyConverter;
use App\Services\Inventory\KitSaleService;
use App\Services\ProductLocationStockService;
use App\Services\ProductService;
use App\Services\StockReservationService;
use App\Services\WarehouseAccessService;
use App\Services\WarehouseStockLevelService;
use App\Support\BackorderSettings;
use App\Support\PluginQueryGuard;
use App\Support\ProductSearch;
use App\Support\Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $organizationId = $request->user()->organization_id;

        $activeWarehouseId = session('active_warehouse_id');

        // Hook: Allow plugins to modify the product query
        $query = Product::with(['category', 'location'])
            ->forOrganization($organizationId)
            // A product sold by variant shows the sum of its variants' stock.
            ->withEffectiveStock()
            ->when($activeWarehouseId, function ($query, $warehouseId) {
                $query->whereHas('location', function ($q) use ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                });
            })
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    Search::apply($q, ['name', 'sku', 'barcode'], $search)
                        // A variant's SKU or barcode finds its product too.
                        ->orWhereHas('variants', function ($variants) use ($search) {
                            Search::apply($variants, ['sku', 'barcode'], $search);
                        });

                    // Hook: Let plugins add search conditions to the OR group
                    ProductSearch::applyPluginFilter($q, (string) $search);
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

        $perPage = (int) $request->input('per_page', 15);
        $perPage = in_array($perPage, [15, 25, 50, 100], true) ? $perPage : 15;
        $products = $query->paginate($perPage)->withQueryString();

        // Display values run the product_display_name / product_price_display filters.
        $products->getCollection()->each->withDisplayValues();
        // The list shows each product's first photo beside its name.
        $products->getCollection()->each->append('image_urls');

        // Hook: Modify products collection before rendering
        $products = apply_filters('product_list_data', $products, $request);

        $categories = ProductCategory::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name']);

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name']);

        // How many of each kit on this page can be made (one batched lookup).
        $kitsOnPage = $products->getCollection()->where('type', 'kit')->values();

        $data = [
            'canResetStock' => $request->user()->isAdmin() && $request->user()->hasPermission('manage_stock'),
            'products' => $products,
            'kitAvailable' => (object) ($kitsOnPage->isEmpty() ? [] : app(KitSaleService::class)->availableFor($kitsOnPage)),
            'filters' => $request->only(['search', 'category', 'location', 'low_stock', 'per_page']),
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
        $organizationId = $request->user()->organization_id;

        $categories = ProductCategory::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name']);

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name', 'code']);

        [$currencies, $defaultCurrency] = $this->currencyOptions((int) $organizationId);

        $productTypes = [
            ['value' => 'standard', 'label' => 'Standard Product'],
            ['value' => 'kit', 'label' => 'Kit (Bundle)'],
            ['value' => 'assembly', 'label' => 'Assembly'],
        ];

        $backorders = BackorderSettings::forOrganization($organizationId);

        return Inertia::render('Products/Create', [
            'categories' => $categories,
            'locations' => $locations,
            'suppliers' => $this->supplierOptions($organizationId),
            'currencies' => $currencies,
            'defaultCurrency' => $defaultCurrency,
            'productTypes' => $productTypes,
            'initialType' => in_array($request->input('type'), array_column($productTypes, 'value'), true) ? $request->input('type') : 'standard',
            'barcodeTypes' => BarcodeType::options(),
            'backordersEnabled' => $backorders->enabled,
            // The organization's default seeds a new product's toggle.
            'backorderDefaultAllow' => $backorders->enabled && $backorders->defaultAllow,
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
            redirect()->route('products.index')->with('success', __('Product created successfully.')),
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
        $product->append('image_urls');
        $eagerLoad = ['category', 'location', 'organization', 'options', 'variants', 'batches', 'serials', 'suppliers'];

        // Eager-load components for kits and assemblies
        if (in_array($product->type, ['kit', 'assembly'])) {
            $eagerLoad[] = 'components.componentProduct';
        }

        $product->load($eagerLoad);

        // Ensure user can only view products from their organization
        if ($product->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Display values run the product_display_name / product_price_display filters.
        $product->withDisplayValues();

        // Hook: Modify product before displaying
        $product = apply_filters('product_show_data', $product, auth()->user());

        // Get product activity history
        $activities = ActivityLog::where('subject_type', Product::class)
            ->where('subject_id', $product->id)
            ->with('user:id,name')
            ->latest()
            ->take(20)
            ->get();

        // Supplier costs are shown in their own currency and, when that is
        // not the organization's, also converted at the organization's stored
        // rate (never a fetched or invented one): today's for the current
        // links, the recorded date's for history rows.
        $converter = app(OrganizationCurrencyConverter::class);
        $organizationId = (int) $product->organization_id;

        $supplierCosts = $product->suppliers
            ->mapWithKeys(function (Supplier $supplier) use ($converter, $organizationId) {
                $currency = ProductSupplier::costCurrencyOf($supplier);

                return [$supplier->id => [
                    'currency' => $currency,
                    'converted' => $converter->toOrganizationCurrency($organizationId, $supplier->pivot?->cost_price, $currency),
                ]];
            })
            ->all();

        // Recent supplier costs (link edits and PO receipts), newest first.
        $priceHistory = SupplierPriceHistory::where('product_id', $product->id)
            ->where('organization_id', $product->organization_id)
            ->with(['supplier:id,name', 'variant:id,title', 'user:id,name', 'purchaseOrder:id,po_number,currency'])
            ->latest('recorded_at')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (SupplierPriceHistory $entry) => $this->priceHistoryRow($entry, $converter, $organizationId));

        $data = [
            'product' => $product,
            'activities' => $activities,
            'priceHistory' => $priceHistory,
            'supplierCosts' => (object) $supplierCosts,
            // Per-location on-hand breakdown (location name + quantity), richest
            // bin first. Empty for products that hold no binned stock.
            // Restricted to the bins in warehouses the viewer can access.
            'variantLocationBreakdown' => VariantLocationStock::query()
                ->where('organization_id', $product->organization_id)
                ->whereHas('variant', fn ($q) => $q->where('product_id', $product->id)->whereNull('deleted_at'))
                ->tap(fn ($q) => app(WarehouseAccessService::class)->scopeByLocation($q, auth()->user(), 'location_id'))
                ->with(['variant:id,sku,title', 'location.warehouse:id,name'])->orderBy('product_variant_id')->get(),
            'locationBreakdown' => app(ProductLocationStockService::class)->breakdown($product, auth()->user()),
            // On-hand and thresholds per warehouse the viewer can access.
            'warehouseStockLevels' => app(WarehouseStockLevelService::class)->levelsForProduct($product, auth()->user()),
            // On hand / reserved / available, for the product and each variant.
            'stockAvailability' => $this->stockAvailability($product),
            // For a kit: how many can be made from its components, and why it
            // cannot be sold when it cannot (null for a kit that can be).
            'kitAvailable' => $product->type === 'kit' ? app(KitSaleService::class)->available($product) : null,
            'kitProblem' => $product->type === 'kit' ? app(KitSaleService::class)->problem($product) : null,
            'pluginComponents' => [
                'header' => get_page_components('products.show', 'header'),
                'actions' => get_page_components('products.show', 'actions'),
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
     * One supplier price history row for the product page.
     *
     * The currency is the one recorded with the row. Rows logged before
     * currencies were recorded have none: a purchase order receipt was logged
     * at the order's unit cost, so it takes the order's currency; a supplier
     * link edit could have been typed in any currency, so it stays unlabelled
     * (currency_known false) instead of being read as the product's.
     *
     * @return array<string, mixed>
     */
    private function priceHistoryRow(SupplierPriceHistory $entry, OrganizationCurrencyConverter $converter, int $organizationId): array
    {
        $currency = ProductSupplier::normalizeCurrency($entry->currency);
        $currencySource = $currency !== null ? 'recorded' : null;

        if ($currency === null && $entry->source === SupplierPriceHistory::SOURCE_PURCHASE_ORDER && $entry->purchaseOrder !== null) {
            $currency = ProductSupplier::normalizeCurrency($entry->purchaseOrder->currency);
            $currencySource = $currency !== null ? 'purchase_order' : null;
        }

        return [
            'id' => $entry->id,
            'supplier_id' => $entry->supplier_id,
            'supplier_name' => $entry->supplier?->name,
            'product_variant_id' => $entry->product_variant_id,
            'variant_title' => $entry->variant?->title,
            'cost_price' => $entry->cost_price,
            'currency' => $currency,
            'currency_known' => $currency !== null,
            'currency_source' => $currencySource,
            'source_cost_price' => $entry->source_cost_price,
            'source_currency' => $entry->source_currency,
            'exchange_rate' => $entry->exchange_rate,
            // A cost converted when it was received already is in the
            // organization's currency; others are converted at the rate
            // stored for the day they were recorded.
            'converted' => $converter->toOrganizationCurrency($organizationId, $entry->cost_price, $currency, $entry->recorded_at),
            'source' => $entry->source,
            'purchase_order_id' => $entry->purchase_order_id,
            'po_number' => $entry->purchaseOrder?->po_number,
            'user_name' => $entry->user?->name,
            'recorded_at' => $entry->recorded_at?->toIso8601String(),
        ];
    }

    /**
     * On hand, reserved and available (on hand less stock reservations) for
     * the product, and per variant for a product sold by variant.
     *
     * @return array{on_hand: int, reserved: int, available: int, variants: array<int, array{on_hand: int, reserved: int, available: int}>}
     */
    private function stockAvailability(Product $product): array
    {
        app(StockReservationService::class)->preloadReserved([$product]);

        return $product->stockAvailability() + [
            'variants' => $product->relationLoaded('variants')
                ? $product->variants->mapWithKeys(fn ($variant) => [$variant->id => $variant->stockAvailability()])->all()
                : [],
        ];
    }

    /**
     * Show the form for editing the specified product.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Product  $product  The product to edit
     */
    public function edit(Request $request, Product $product): Response
    {
        $product->append('image_urls');
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

        // Load options, variants and supplier links
        $product->load(['options', 'variants', 'suppliers']);

        [$currencies, $defaultCurrency] = $this->currencyOptions((int) $organizationId);

        $productTypes = [
            ['value' => 'standard', 'label' => 'Standard Product'],
            ['value' => 'kit', 'label' => 'Kit (Bundle)'],
            ['value' => 'assembly', 'label' => 'Assembly'],
        ];

        return Inertia::render('Products/Edit', [
            'product' => $product,
            'categories' => $categories,
            'locations' => $locations,
            'suppliers' => $this->supplierOptions($organizationId),
            'currencies' => $currencies,
            'defaultCurrency' => $defaultCurrency,
            'productTypes' => $productTypes,
            'barcodeTypes' => BarcodeType::options(),
            'backordersEnabled' => BackorderSettings::forOrganization($organizationId)->enabled,
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

        // Action: After update (product_updated is fired by ProductObserver
        // for every surface, once the edit commits).
        do_action('product_after_update', $product, $request);

        // "Save and view" on the edit page returns to the product (#461).
        $route = $request->input('redirect_to') === 'show'
            ? redirect()->route('products.show', $product)
            : redirect()->route('products.index');

        return $route->with('success', __('Product updated successfully.'));
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
            ->with('success', __('Product duplicated successfully.'));
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

        // Action: After delete (product_deleted is fired by ProductObserver
        // for every surface).
        do_action('product_after_delete', $product, $request);

        return redirect()->route('products.index')
            ->with('success', __('Product deleted successfully.'));
    }

    /**
     * The currencies the product form offers and the one it starts on: the
     * organization's currency (as orders and purchase orders use), which is
     * kept in the list even if it has since left the supported set.
     *
     * @return array{0: array<string, array<string, string>>, 1: string}
     */
    private function currencyOptions(int $organizationId): array
    {
        $defaultCurrency = Organization::currencyFor($organizationId);
        $currencies = (array) config('currencies.supported', []);

        if (! array_key_exists($defaultCurrency, $currencies)) {
            $currencies = [$defaultCurrency => ['symbol' => $defaultCurrency, 'name' => $defaultCurrency]] + $currencies;
        }

        return [$currencies, $defaultCurrency];
    }

    /**
     * Active suppliers of the organization, for the product form's supplier picker.
     *
     * @return Collection<int, Supplier>
     */
    private function supplierOptions(int $organizationId)
    {
        return Supplier::forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'currency']);
    }
}
