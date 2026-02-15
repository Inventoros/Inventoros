<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
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
    /**
     * Display a listing of stock adjustments.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $query = StockAdjustment::with(['product', 'user'])
            ->forOrganization($organizationId)
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

        $users = \App\Models\User::forOrganization($organizationId)
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
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $products = Product::forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'stock']);

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
        ]);
    }

    /**
     * Store a newly created stock adjustment.
     *
     * @param Request $request The incoming HTTP request containing adjustment data
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:manual,recount,damage,loss,return,correction',
            'adjustment_quantity' => 'required|integer|not_in:0',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // Get the product and ensure it belongs to the user's organization
        $product = Product::where('id', $validated['product_id'])
            ->forOrganization($request->user()->organization_id)
            ->firstOrFail();

        // Create the adjustment
        StockAdjustment::adjust(
            product: $product,
            quantity: $validated['adjustment_quantity'],
            type: $validated['type'],
            reason: $validated['reason'],
            notes: $validated['notes'] ?? null
        );

        return redirect()->route('stock-adjustments.index')
            ->with('success', 'Stock adjustment created successfully.');
    }

    /**
     * Display the specified stock adjustment.
     *
     * @param Request $request The incoming HTTP request
     * @param StockAdjustment $stockAdjustment The stock adjustment to display
     * @return Response
     */
    public function show(Request $request, StockAdjustment $stockAdjustment): Response
    {
        // Ensure the adjustment belongs to the user's organization
        if ($stockAdjustment->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $stockAdjustment->load(['product', 'user', 'reference']);

        return Inertia::render('StockAdjustments/Show', [
            'adjustment' => $stockAdjustment,
        ]);
    }
}
