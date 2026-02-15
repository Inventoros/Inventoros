<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\SKUGeneratorService;
use Illuminate\Http\Request;

/**
 * Controller for SKU generation and validation.
 *
 * Handles generating unique SKUs based on patterns,
 * checking SKU uniqueness, and retrieving available patterns.
 */
class SKUController extends Controller
{
    /**
     * @var SKUGeneratorService The SKU generator service instance
     */
    protected $skuGenerator;

    /**
     * Create a new controller instance.
     *
     * @param SKUGeneratorService $skuGenerator The SKU generator service instance
     */
    public function __construct(SKUGeneratorService $skuGenerator)
    {
        $this->skuGenerator = $skuGenerator;
    }

    /**
     * Generate SKU based on pattern.
     *
     * @param Request $request The incoming HTTP request containing pattern data
     * @return \Illuminate\Http\JsonResponse
     */
    public function generate(Request $request)
    {
        $request->validate([
            'pattern' => 'required|string',
            'product_name' => 'nullable|string',
            'category_id' => 'nullable|integer',
        ]);

        $sku = $this->skuGenerator->generateUnique(
            $request->pattern,
            $request->user()->organization_id,
            $request->product_name,
            $request->category_id
        );

        return response()->json([
            'sku' => $sku,
        ]);
    }

    /**
     * Check if SKU is unique.
     *
     * @param Request $request The incoming HTTP request containing SKU to check
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkUnique(Request $request)
    {
        $request->validate([
            'sku' => 'required|string',
            'product_id' => 'nullable|integer',
        ]);

        $isUnique = $this->skuGenerator->isUnique(
            $request->sku,
            $request->user()->organization_id,
            $request->product_id
        );

        return response()->json([
            'unique' => $isUnique,
        ]);
    }

    /**
     * Get available patterns.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function patterns()
    {
        return response()->json([
            'variables' => SKUGeneratorService::getAvailablePatterns(),
            'presets' => SKUGeneratorService::getPresetPatterns(),
        ]);
    }
}
