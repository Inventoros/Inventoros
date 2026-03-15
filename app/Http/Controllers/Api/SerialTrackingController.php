<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductSerialResource;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductSerial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * @tags Serial Tracking
 */
class SerialTrackingController extends Controller
{
    /**
     * List serial numbers for a product.
     */
    public function index(Request $request, Product $product): AnonymousResourceCollection|JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $query = $product->serials();

        if ($request->has('status')) {
            $query->withStatus($request->input('status'));
        }

        $serials = $query->latest()->paginate($request->input('per_page', 15));

        return ProductSerialResource::collection($serials);
    }

    /**
     * Create a new serial number for a product.
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        if ($product->tracking_type !== 'serial') {
            return response()->json([
                'message' => 'This product does not use serial tracking.',
                'errors' => ['tracking_type' => ['This product does not use serial tracking.']],
            ], 422);
        }

        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'serial_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_serials')->where(function ($query) use ($organizationId) {
                    return $query->where('organization_id', $organizationId);
                }),
            ],
            'status' => ['nullable', 'string', Rule::in(ProductSerial::VALID_STATUSES)],
            'notes' => ['nullable', 'string'],
        ]);

        $validated['organization_id'] = $organizationId;
        $validated['product_id'] = $product->id;
        $validated['status'] = $validated['status'] ?? ProductSerial::STATUS_AVAILABLE;

        $serial = ProductSerial::create($validated);

        return response()->json([
            'message' => 'Serial number created successfully',
            'data' => new ProductSerialResource($serial),
        ], 201);
    }

    /**
     * Display a specific serial number.
     */
    public function show(Request $request, Product $product, ProductSerial $serial): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        if ($serial->product_id !== $product->id) {
            return response()->json(['message' => 'Serial not found'], 404);
        }

        return response()->json([
            'data' => new ProductSerialResource($serial),
        ]);
    }

    /**
     * Update a serial number (primarily status changes).
     */
    public function update(Request $request, Product $product, ProductSerial $serial): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        if ($serial->product_id !== $product->id) {
            return response()->json(['message' => 'Serial not found'], 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(ProductSerial::VALID_STATUSES)],
            'notes' => ['nullable', 'string'],
        ]);

        $serial->update($validated);

        return response()->json([
            'message' => 'Serial number updated successfully',
            'data' => new ProductSerialResource($serial),
        ]);
    }
}
