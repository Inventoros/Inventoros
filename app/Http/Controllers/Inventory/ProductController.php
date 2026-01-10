<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductOption;
use App\Models\Inventory\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Hook: Allow plugins to modify the product query
        $query = Product::with(['category', 'location'])
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
            ->latest();

        // Hook: Modify product list query
        $query = apply_filters('product_list_query', $query, $request);

        $products = $query->paginate(15)->withQueryString();

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

        $currencies = config('currencies.supported');
        $defaultCurrency = config('currencies.default');

        return Inertia::render('Products/Create', [
            'categories' => $categories,
            'locations' => $locations,
            'currencies' => $currencies,
            'defaultCurrency' => $defaultCurrency,
            'pluginComponents' => [
                'header' => get_page_components('products.create', 'header'),
                'beforeForm' => get_page_components('products.create', 'before-form'),
                'afterForm' => get_page_components('products.create', 'after-form'),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Hook: Modify validation rules before validation
        $rules = apply_filters('product_store_validation_rules', [
            'sku' => 'required|string|max:255|unique:products,sku',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'currency' => 'required|string|max:3',
            'price_in_currencies' => 'nullable|array',
            'price_in_currencies.*.currency' => 'required|string|max:3',
            'price_in_currencies.*.price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'min_stock' => 'required|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'barcode' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'location_id' => 'nullable|exists:product_locations,id',
            'is_active' => 'boolean',
            'images' => 'nullable|array|max:5',
            'images.*.file' => 'nullable',
            'images.*.preview' => 'nullable|string',
            'images.*.name' => 'nullable|string',
            'has_variants' => 'boolean',
            'options' => 'nullable|array|max:3',
            'options.*.name' => 'required_with:options|string|max:255',
            'options.*.values' => 'required_with:options|array|min:1',
            'options.*.values.*' => 'string|max:255',
            'variants' => 'nullable|array',
            'variants.*.option_values' => 'required_with:variants|array',
            'variants.*.sku' => 'nullable|string|max:255',
            'variants.*.barcode' => 'nullable|string|max:255',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.purchase_price' => 'nullable|numeric|min:0',
            'variants.*.stock' => 'nullable|integer|min:0',
            'variants.*.min_stock' => 'nullable|integer|min:0',
            'variants.*.is_active' => 'boolean',
        ], $request);

        $validated = $request->validate($rules);

        $validated['organization_id'] = $request->user()->organization_id;

        // Hook: Allow plugins to modify validated data before saving
        $validated = apply_filters('product_store_data', $validated, $request);

        // Action: Before product creation
        do_action('product_before_create', $validated, $request);

        // Convert price_in_currencies array to proper format
        if (!empty($validated['price_in_currencies'])) {
            $currencies = [];
            foreach ($validated['price_in_currencies'] as $currencyPrice) {
                $currencies[$currencyPrice['currency']] = $currencyPrice['price'];
            }
            $validated['price_in_currencies'] = $currencies;
        }

        // Handle image uploads
        if (!empty($validated['images'])) {
            $imagePaths = [];
            foreach ($validated['images'] as $index => $imageData) {
                if (isset($imageData['preview']) && str_starts_with($imageData['preview'], 'data:image/')) {
                    // Extract base64 data
                    $base64 = substr($imageData['preview'], strpos($imageData['preview'], ',') + 1);
                    $imageContent = base64_decode($base64);

                    // Generate unique filename
                    $extension = $this->getImageExtensionFromBase64($imageData['preview']);
                    $filename = 'products/' . uniqid() . '_' . time() . '.' . $extension;

                    // Store image
                    \Storage::disk('public')->put($filename, $imageContent);
                    $imagePaths[] = $filename;

                    // Set thumbnail (first image)
                    if ($index === 0) {
                        $validated['thumbnail'] = $filename;
                    }
                }
            }
            $validated['images'] = $imagePaths;
        }

        // Extract options and variants before creating product
        $options = $validated['options'] ?? [];
        $variants = $validated['variants'] ?? [];
        unset($validated['options'], $validated['variants']);

        $product = DB::transaction(function () use ($validated, $options, $variants, $request) {
            $product = Product::create($validated);

            // Create options if has_variants is true
            if ($product->has_variants && !empty($options)) {
                foreach ($options as $index => $optionData) {
                    ProductOption::create([
                        'product_id' => $product->id,
                        'name' => $optionData['name'],
                        'values' => $optionData['values'],
                        'position' => $index,
                    ]);
                }

                // Create variants
                foreach ($variants as $index => $variantData) {
                    ProductVariant::create([
                        'product_id' => $product->id,
                        'organization_id' => $product->organization_id,
                        'sku' => $variantData['sku'] ?? null,
                        'barcode' => $variantData['barcode'] ?? null,
                        'title' => $variantData['title'] ?? implode(' / ', array_values($variantData['option_values'])),
                        'option_values' => $variantData['option_values'],
                        'price' => $variantData['price'] ?? null,
                        'purchase_price' => $variantData['purchase_price'] ?? null,
                        'stock' => $variantData['stock'] ?? 0,
                        'min_stock' => $variantData['min_stock'] ?? 0,
                        'is_active' => $variantData['is_active'] ?? true,
                        'position' => $index,
                    ]);
                }
            }

            return $product;
        });

        // Action: After product creation
        do_action('product_created', $product, $request->user());
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
     * Get image extension from base64 string
     */
    private function getImageExtensionFromBase64(string $base64): string
    {
        $mimeType = substr($base64, 5, strpos($base64, ';') - 5);
        return match($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product): Response
    {
        $product->load(['category', 'location', 'organization', 'options', 'variants']);

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

        // Load options and variants
        $product->load(['options', 'variants']);

        $currencies = config('currencies.supported');
        $defaultCurrency = config('currencies.default');

        return Inertia::render('Products/Edit', [
            'product' => $product,
            'categories' => $categories,
            'locations' => $locations,
            'currencies' => $currencies,
            'defaultCurrency' => $defaultCurrency,
            'pluginComponents' => [
                'header' => get_page_components('products.edit', 'header'),
                'beforeForm' => get_page_components('products.edit', 'before-form'),
                'afterForm' => get_page_components('products.edit', 'after-form'),
            ],
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

        // Hook: Modify validation rules
        $rules = apply_filters('product_update_validation_rules', [
            'sku' => 'required|string|max:255|unique:products,sku,' . $product->id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'min_stock' => 'required|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'barcode' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'location_id' => 'nullable|exists:product_locations,id',
            'is_active' => 'boolean',
            'images' => 'nullable|array|max:5',
            'images.*.file' => 'nullable',
            'images.*.preview' => 'nullable|string',
            'images.*.url' => 'nullable|string',
            'images.*.name' => 'nullable|string',
            'has_variants' => 'boolean',
            'options' => 'nullable|array|max:3',
            'options.*.id' => 'nullable|integer',
            'options.*.name' => 'required_with:options|string|max:255',
            'options.*.values' => 'required_with:options|array|min:1',
            'options.*.values.*' => 'string|max:255',
            'variants' => 'nullable|array',
            'variants.*.id' => 'nullable|integer',
            'variants.*.option_values' => 'required_with:variants|array',
            'variants.*.sku' => 'nullable|string|max:255',
            'variants.*.barcode' => 'nullable|string|max:255',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.purchase_price' => 'nullable|numeric|min:0',
            'variants.*.stock' => 'nullable|integer|min:0',
            'variants.*.min_stock' => 'nullable|integer|min:0',
            'variants.*.is_active' => 'boolean',
        ], $product, $request);

        $validated = $request->validate($rules);

        // Hook: Modify validated data
        $validated = apply_filters('product_update_data', $validated, $product, $request);

        // Action: Before update
        do_action('product_before_update', $product, $validated, $request);

        // Handle image uploads
        if (isset($validated['images'])) {
            $imagePaths = [];
            $oldImages = $product->images ?? [];

            foreach ($validated['images'] as $index => $imageData) {
                // If image has a URL (existing image), keep it
                if (isset($imageData['url']) && !str_starts_with($imageData['preview'], 'data:image/')) {
                    // Extract path from URL (/storage/products/...)
                    $path = str_replace('/storage/', '', $imageData['url']);
                    $imagePaths[] = $path;
                }
                // If image has preview as base64 (new image), upload it
                elseif (isset($imageData['preview']) && str_starts_with($imageData['preview'], 'data:image/')) {
                    $base64 = substr($imageData['preview'], strpos($imageData['preview'], ',') + 1);
                    $imageContent = base64_decode($base64);

                    $extension = $this->getImageExtensionFromBase64($imageData['preview']);
                    $filename = 'products/' . uniqid() . '_' . time() . '.' . $extension;

                    \Storage::disk('public')->put($filename, $imageContent);
                    $imagePaths[] = $filename;
                }

                // Set thumbnail (first image)
                if ($index === 0 && !empty($imagePaths)) {
                    $validated['thumbnail'] = end($imagePaths);
                }
            }

            // Delete removed images
            foreach ($oldImages as $oldImage) {
                if (!in_array($oldImage, $imagePaths)) {
                    \Storage::disk('public')->delete($oldImage);
                }
            }

            $validated['images'] = $imagePaths;
        }

        // Extract options and variants before updating product
        $options = $validated['options'] ?? [];
        $variants = $validated['variants'] ?? [];
        unset($validated['options'], $validated['variants']);

        DB::transaction(function () use ($product, $validated, $options, $variants) {
            $product->update($validated);

            // Sync options and variants if has_variants is true
            if ($validated['has_variants'] ?? $product->has_variants) {
                $this->syncOptions($product, $options);
                $this->syncVariants($product, $variants);
            } else {
                // If variants are disabled, delete all options and variants
                $product->options()->delete();
                $product->variants()->delete();
            }
        });

        // Action: After update
        do_action('product_updated', $product, $request->user());
        do_action('product_after_update', $product, $request);

        return redirect()->route('products.index')
            ->with('success', 'Product updated successfully.');
    }

    /**
     * Sync product options.
     */
    private function syncOptions(Product $product, array $options): void
    {
        $existingOptionIds = $product->options()->pluck('id')->toArray();
        $incomingOptionIds = [];

        foreach ($options as $index => $optionData) {
            if (!empty($optionData['id'])) {
                // Update existing option
                $option = ProductOption::find($optionData['id']);
                if ($option && $option->product_id === $product->id) {
                    $option->update([
                        'name' => $optionData['name'],
                        'values' => $optionData['values'],
                        'position' => $index,
                    ]);
                    $incomingOptionIds[] = $optionData['id'];
                }
            } else {
                // Create new option
                $option = ProductOption::create([
                    'product_id' => $product->id,
                    'name' => $optionData['name'],
                    'values' => $optionData['values'],
                    'position' => $index,
                ]);
                $incomingOptionIds[] = $option->id;
            }
        }

        // Delete removed options
        $optionsToDelete = array_diff($existingOptionIds, $incomingOptionIds);
        if (!empty($optionsToDelete)) {
            ProductOption::whereIn('id', $optionsToDelete)->delete();
        }
    }

    /**
     * Sync product variants.
     */
    private function syncVariants(Product $product, array $variants): void
    {
        $existingVariantIds = $product->variants()->pluck('id')->toArray();
        $incomingVariantIds = [];

        foreach ($variants as $index => $variantData) {
            $variantPayload = [
                'product_id' => $product->id,
                'organization_id' => $product->organization_id,
                'sku' => $variantData['sku'] ?? null,
                'barcode' => $variantData['barcode'] ?? null,
                'title' => $variantData['title'] ?? implode(' / ', array_values($variantData['option_values'])),
                'option_values' => $variantData['option_values'],
                'price' => $variantData['price'] ?? null,
                'purchase_price' => $variantData['purchase_price'] ?? null,
                'stock' => $variantData['stock'] ?? 0,
                'min_stock' => $variantData['min_stock'] ?? 0,
                'is_active' => $variantData['is_active'] ?? true,
                'position' => $index,
            ];

            if (!empty($variantData['id'])) {
                // Update existing variant
                $variant = ProductVariant::find($variantData['id']);
                if ($variant && $variant->product_id === $product->id) {
                    $variant->update($variantPayload);
                    $incomingVariantIds[] = $variantData['id'];
                }
            } else {
                // Create new variant
                $variant = ProductVariant::create($variantPayload);
                $incomingVariantIds[] = $variant->id;
            }
        }

        // Delete removed variants
        $variantsToDelete = array_diff($existingVariantIds, $incomingVariantIds);
        if (!empty($variantsToDelete)) {
            ProductVariant::whereIn('id', $variantsToDelete)->delete();
        }
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
