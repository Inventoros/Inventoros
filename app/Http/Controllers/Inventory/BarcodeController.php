<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Services\BarcodeService;
use Illuminate\Http\Request;

class BarcodeController extends Controller
{
    protected $barcodeService;

    public function __construct(BarcodeService $barcodeService)
    {
        $this->barcodeService = $barcodeService;
    }

    /**
     * Generate barcode image for a product
     */
    public function generate(Request $request, Product $product)
    {
        // Verify user has access to this product's organization
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403);
        }

        $code = $product->barcode ?? $product->sku;

        // Generate PNG barcode
        $barcodeImage = $this->barcodeService->generatePNG($code);

        return response()->json([
            'barcode' => 'data:image/png;base64,' . $barcodeImage,
            'code' => $code,
        ]);
    }

    /**
     * Generate barcode for printing
     */
    public function print(Request $request, Product $product)
    {
        // Verify user has access to this product's organization
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403);
        }

        $code = $product->barcode ?? $product->sku;

        // Generate SVG for better print quality
        $barcodeSVG = $this->barcodeService->generateSVG($code, 3, 80);

        $html = view('barcode.print', [
            'product' => $product,
            'barcode' => $barcodeSVG,
            'code' => $code,
        ])->render();

        return response($html, 200)
            ->header('Content-Type', 'text/html');
    }

    /**
     * Generate random barcode for a product
     */
    public function generateRandom(Request $request, Product $product)
    {
        // Verify user has access to this product's organization
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403);
        }

        $barcode = $this->barcodeService->generateRandomBarcode();

        // Update product with new barcode
        $product->update(['barcode' => $barcode]);

        return response()->json([
            'barcode' => $barcode,
            'message' => 'Barcode generated successfully',
        ]);
    }

    /**
     * Generate barcode from SKU
     */
    public function generateFromSKU(Request $request, Product $product)
    {
        // Verify user has access to this product's organization
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403);
        }

        $barcode = $this->barcodeService->generateFromSKU($product->sku);

        // Update product with new barcode
        $product->update(['barcode' => $barcode]);

        return response()->json([
            'barcode' => $barcode,
            'message' => 'Barcode generated from SKU successfully',
        ]);
    }
}
