<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Services\BarcodeService;
use Illuminate\Http\Request;

/**
 * Controller for managing product barcodes.
 *
 * Handles barcode generation, printing, and lookup functionality
 * for inventory products.
 */
class BarcodeController extends Controller
{
    /**
     * @var BarcodeService The barcode service instance
     */
    protected $barcodeService;

    /**
     * Create a new controller instance.
     *
     * @param BarcodeService $barcodeService The barcode service instance
     */
    public function __construct(BarcodeService $barcodeService)
    {
        $this->barcodeService = $barcodeService;
    }

    /**
     * Generate barcode image for a product.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The product to generate barcode for
     * @return \Illuminate\Http\JsonResponse
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
     * Generate barcode for printing.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The product to print barcode for
     * @return \Illuminate\Http\Response
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
     * Generate random barcode for a product.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The product to generate random barcode for
     * @return \Illuminate\Http\JsonResponse
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
     * Generate barcode from SKU.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The product to generate barcode from SKU for
     * @return \Illuminate\Http\JsonResponse
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

    /**
     * Bulk print barcodes for multiple products.
     *
     * @param Request $request The incoming HTTP request containing product IDs
     * @return \Illuminate\View\View
     */
    public function bulkPrint(Request $request)
    {
        $ids = array_filter(explode(',', $request->query('ids', '')));

        if (empty($ids)) {
            abort(400, 'No product IDs provided');
        }

        $products = Product::whereIn('id', $ids)
            ->where('organization_id', $request->user()->organization_id)
            ->get();

        $barcodes = [];
        foreach ($products as $product) {
            $code = $product->barcode ?? $product->sku;
            if ($code) {
                $barcodes[] = [
                    'product' => $product,
                    'barcode' => $this->barcodeService->generateSVG($code, 2, 60),
                    'code' => $code,
                ];
            }
        }

        return view('barcode.bulk-print', [
            'barcodes' => $barcodes,
        ]);
    }
}
