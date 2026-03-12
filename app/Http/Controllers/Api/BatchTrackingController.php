<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductBatchResource;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Batch Tracking
 */
class BatchTrackingController extends Controller
{
    /**
     * List batches for a product.
     */
    public function index(Request $request, Product $product): AnonymousResourceCollection|JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $batches = $product->batches()
            ->latest()
            ->paginate($request->input('per_page', 15));

        return ProductBatchResource::collection($batches);
    }

    /**
     * Create a new batch for a product.
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        if ($product->tracking_type !== 'batch') {
            return response()->json([
                'message' => 'This product does not use batch tracking.',
                'errors' => ['tracking_type' => ['This product does not use batch tracking.']],
            ], 422);
        }

        $validated = $request->validate([
            'batch_number' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:0'],
            'manufactured_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:manufactured_date'],
            'notes' => ['nullable', 'string'],
        ]);

        $organizationId = $request->user()->organization_id;

        // Auto-generate batch number if not provided
        if (empty($validated['batch_number'])) {
            $validated['batch_number'] = ProductBatch::generateBatchNumber($organizationId);
        }

        $validated['organization_id'] = $organizationId;
        $validated['product_id'] = $product->id;

        $batch = ProductBatch::create($validated);

        return response()->json([
            'message' => 'Batch created successfully',
            'data' => new ProductBatchResource($batch),
        ], 201);
    }

    /**
     * Display a specific batch.
     */
    public function show(Request $request, Product $product, ProductBatch $batch): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        if ($batch->product_id !== $product->id) {
            return response()->json(['message' => 'Batch not found'], 404);
        }

        return response()->json([
            'data' => new ProductBatchResource($batch),
        ]);
    }
}
