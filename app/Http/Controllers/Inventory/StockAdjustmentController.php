<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustment\StoreStockAdjustmentRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\User;
use App\Services\WarehouseAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing stock adjustments.
 *
 * Handles listing, creating, and viewing stock adjustment records
 * for inventory management.
 */
class StockAdjustmentController extends Controller
{
    public function __construct(private readonly WarehouseAccessService $warehouseAccess) {}

    /**
     * Display a listing of stock adjustments.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $query = StockAdjustment::with(['product', 'user'])
            ->forOrganization($organizationId)
            ->tap(fn ($q) => $this->warehouseAccess->scopeByLocation($q, $request->user(), 'stock_adjustments.location_id'))
            ->when($request->input('search'), function ($query, $search) {
                $query->whereHas('product', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($request->input('type'), function ($query, $type) {
                $query->where('type', $type);
            })
            ->when($request->input('product_id'), function ($query, $productId) {
                $query->where('product_id', $productId);
            })
            ->when($request->input('user_id'), function ($query, $userId) {
                $query->where('user_id', $userId);
            })
            ->when($request->input('date_from'), function ($query, $dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            })
            ->when($request->input('date_to'), function ($query, $dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            })
            ->latest();

        $adjustments = $query->paginate(20)->withQueryString();

        // Get filter options
        $products = Product::forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'sku']);

        $users = User::forOrganization($organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $types = [
            'manual' => 'Manual Adjustment',
            'order' => 'Order',
            'return' => 'Return',
            'damage' => 'Damage',
            'loss' => 'Loss',
            'recount' => 'Recount',
            'correction' => 'Correction',
        ];

        return Inertia::render('StockAdjustments/Index', [
            'adjustments' => $adjustments,
            'filters' => $request->only(['search', 'type', 'product_id', 'user_id', 'date_from', 'date_to']),
            'products' => $products,
            'users' => $users,
            'types' => $types,
        ]);
    }

    /**
     * Show the form for creating a new stock adjustment.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        // Each product carries its active variants so the page can adjust a
        // variant, picked by hand or resolved from a scanned variant barcode.
        $products = Product::forOrganization($organizationId)
            ->active()
            ->with('activeVariants')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'stock', 'has_variants'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'stock' => (int) $product->stock,
                'has_variants' => (bool) $product->has_variants,
                'variants' => $product->has_variants
                    ? $product->activeVariants->map(fn (ProductVariant $variant) => [
                        'id' => $variant->id,
                        'title' => $variant->title,
                        'sku' => $variant->sku,
                        'stock' => (int) $variant->stock,
                    ])->values()->all()
                    : [],
            ])
            ->values();

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->tap(fn ($q) => $this->warehouseAccess->scopeLocations($q, $request->user()))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $types = [
            'manual' => 'Manual Adjustment',
            'recount' => 'Stock Recount',
            'damage' => 'Damage',
            'loss' => 'Loss',
            'return' => 'Return',
            'correction' => 'Correction',
        ];

        return Inertia::render('StockAdjustments/Create', [
            'products' => $products,
            'types' => $types,
            'locations' => $locations,
        ]);
    }

    /**
     * Store a newly created stock adjustment.
     *
     * @param  Request  $request  The incoming HTTP request containing adjustment data
     * @return RedirectResponse
     */
    public function store(StoreStockAdjustmentRequest $request)
    {
        $validated = $request->validated();

        // Get the product and ensure it belongs to the user's organization
        $product = Product::where('id', $validated['product_id'])
            ->forOrganization($request->user()->organization_id)
            ->firstOrFail();

        // A restricted user may only move stock in a bin of one of their
        // warehouses; an adjustment without a bin changes the org-wide total.
        $this->warehouseAccess->authorizeLocation($request->user(), $validated['location_id'] ?? null);

        // Create the adjustment
        try {
            if (! empty($validated['product_variant_id'])) {
                // The request confirmed the variant belongs to this product;
                // variant stock moves through the variant ledger path.
                $variant = ProductVariant::where('product_id', $product->id)
                    ->findOrFail($validated['product_variant_id']);

                StockAdjustment::adjustVariant(
                    variant: $variant,
                    quantity: $validated['adjustment_quantity'],
                    type: $validated['type'],
                    reason: $validated['reason'],
                    notes: $validated['notes'] ?? null,
                    allowNegative: false,
                );

                return redirect()->route('stock-adjustments.index')
                    ->with('success', 'Stock adjustment created successfully.');
            }

            StockAdjustment::adjust(
                product: $product,
                quantity: $validated['adjustment_quantity'],
                type: $validated['type'],
                reason: $validated['reason'],
                notes: $validated['notes'] ?? null,
                allowNegative: false,
                locationId: $validated['location_id'] ?? null,
            );
        } catch (InsufficientStockException $e) {
            return redirect()->back()
                ->withErrors(['adjustment_quantity' => $e->getMessage()])
                ->withInput();
        }

        return redirect()->route('stock-adjustments.index')
            ->with('success', 'Stock adjustment created successfully.');
    }

    /**
     * Display the specified stock adjustment.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockAdjustment  $stockAdjustment  The stock adjustment to display
     */
    public function show(Request $request, StockAdjustment $stockAdjustment): Response
    {
        // Ensure the adjustment belongs to the user's organization
        if ($stockAdjustment->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeLocation($request->user(), $stockAdjustment->location_id);

        $stockAdjustment->load(['product', 'user', 'reference', 'location']);

        return Inertia::render('StockAdjustments/Show', [
            'adjustment' => $stockAdjustment,
        ]);
    }
}
