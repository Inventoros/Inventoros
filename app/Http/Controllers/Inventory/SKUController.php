<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\SKUGeneratorService;
use Illuminate\Http\Request;

class SKUController extends Controller
{
    protected $skuGenerator;

    public function __construct(SKUGeneratorService $skuGenerator)
    {
        $this->skuGenerator = $skuGenerator;
    }

    /**
     * Generate SKU based on pattern
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
     * Check if SKU is unique
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
     * Get available patterns
     */
    public function patterns()
    {
        return response()->json([
            'variables' => SKUGeneratorService::getAvailablePatterns(),
            'presets' => SKUGeneratorService::getPresetPatterns(),
        ]);
    }
}
