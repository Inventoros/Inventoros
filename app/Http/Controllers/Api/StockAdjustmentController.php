<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockAdjustmentResource;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * API Controller for managing stock adjustments.
 *
 * Handles RESTful API operations for stock adjustment records
 * including listing and creating adjustments.
 */
class StockAdjustmentController extends Controller
{
    /**
     * Display a listing of stock adjustments.
     *
     * @param Request $request The incoming HTTP request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $request->user()->organization_id;

        $query = StockAdjustment::with(['product', 'user'])
            ->forOrganization($organizationId)
            ->when($request->input('product_id'), function ($query, $productId) {
                $query->forProduct($productId);
            })
            ->when($request->input('type'), function ($query, $type) {
                $query->ofType($type);
            })
            ->when($request->input('date_from'), function ($query, $dateFrom) {
                $query->where('created_at', '>=', $dateFrom);
            })
            ->when($request->input('date_to'), function ($query, $dateTo) {
                $query->where('created_at', '<=', $dateTo);
            });

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $adjustments = $query->paginate($perPage);

        return StockAdjustmentResource::collection($adjustments);
    }

    /**
     * Store a newly created stock adjustment.
     *
     * @param Request $request The incoming HTTP request containing adjustment data
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer'],
            'type' => ['required', 'string', 'in:manual,count,damage,return,transfer'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $organizationId = $request->user()->organization_id;

        // Verify the product belongs to the organization
        $product = Product::where('id', $validated['product_id'])
            ->where('organization_id', $organizationId)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found',
                'error' => 'not_found',
            ], 404);
        }

        // Create the stock adjustment
        $adjustment = StockAdjustment::adjust(
            $product,
            $validated['quantity'],
            $validated['type'],
            $validated['reason'] ?? null,
            $validated['notes'] ?? null
        );

        $adjustment->load(['product', 'user']);

        return response()->json([
            'message' => 'Stock adjustment created successfully',
            'data' => new StockAdjustmentResource($adjustment),
        ], 201);
    }

    /**
     * Display the specified stock adjustment.
     *
     * @param Request $request The incoming HTTP request
     * @param StockAdjustment $stockAdjustment The stock adjustment to display
     * @return JsonResponse
     */
    public function show(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        if ($stockAdjustment->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Stock adjustment not found',
                'error' => 'not_found',
            ], 404);
        }

        $stockAdjustment->load(['product', 'user']);

        return response()->json([
            'data' => new StockAdjustmentResource($stockAdjustment),
        ]);
    }
}
