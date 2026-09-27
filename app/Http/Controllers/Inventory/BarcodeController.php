<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Enums\BarcodeType;
use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Services\BarcodeService;
use Illuminate\Http\Request;

/**
 * Controller for managing product barcodes.
 *
 * Handles barcode generation, printing, and lookup functionality
 * for inventory products. Each product prints in its own symbology
 * (products.barcode_type, or detected from the value); print and bulk print
 * accept a `type` query parameter to choose one for that print run.
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

        $code = (string) $product->barcodeValue();
        $type = $this->typeForSingleProduct($request, $product, $code);

        // Generate PNG barcode
        $barcodeImage = $this->barcodeService->generatePNG($code, 2, 50, $type);

        return response()->json([
            'barcode' => 'data:image/png;base64,' . $barcodeImage,
            'code' => $code,
            'type' => $type->value,
            'type_label' => $type->label(),
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

        $code = (string) $product->barcodeValue();
        $type = $this->typeForSingleProduct($request, $product, $code);

        // Generate SVG for better print quality
        $barcodeSVG = $this->barcodeService->generateSVG($code, 3, 80, $type);

        $html = view('barcode.print', [
            'product' => $product,
            'barcode' => $barcodeSVG,
            'code' => $code,
            'type' => $type,
            'selectedType' => $request->query('type', 'auto'),
            'types' => BarcodeType::cases(),
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

        // The generated value is a valid EAN-13, so print it as one.
        $product->update(['barcode' => $barcode, 'barcode_type' => BarcodeType::EAN_13->value]);

        return response()->json([
            'barcode' => $barcode,
            'type' => BarcodeType::EAN_13->value,
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

        // The generated value is a valid EAN-13, so print it as one.
        $product->update(['barcode' => $barcode, 'barcode_type' => BarcodeType::EAN_13->value]);

        return response()->json([
            'barcode' => $barcode,
            'type' => BarcodeType::EAN_13->value,
            'message' => 'Barcode generated from SKU successfully',
        ]);
    }

    /**
     * Bulk print barcodes for multiple products.
     *
     * With `type`, every product whose value is valid for that symbology
     * prints in it; the rest keep their own symbology and are flagged.
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

        $requested = $this->requestedType($request);

        $products = Product::whereIn('id', $ids)
            ->where('organization_id', $request->user()->organization_id)
            ->get();

        $barcodes = [];
        foreach ($products as $product) {
            $code = $product->barcodeValue();
            if ($code) {
                $useRequested = $requested !== null && $requested->isValid($code);
                $type = $useRequested ? $requested : $product->resolvedBarcodeType($code);

                $barcodes[] = [
                    'product' => $product,
                    'barcode' => $this->barcodeService->generateSVG($code, 2, 60, $type),
                    'code' => $code,
                    'type' => $type,
                    'fallback' => $requested !== null && ! $useRequested,
                ];
            }
        }

        return view('barcode.bulk-print', [
            'barcodes' => $barcodes,
            'ids' => implode(',', $products->pluck('id')->all()),
            'selectedType' => $requested?->value ?? 'auto',
            'types' => BarcodeType::cases(),
        ]);
    }

    /**
     * The symbology to render one product in: the requested `type` (which
     * must fit the value, else 422) or the product's own.
     */
    private function typeForSingleProduct(Request $request, Product $product, string $code): BarcodeType
    {
        $requested = $this->requestedType($request);

        if ($requested === null) {
            return $product->resolvedBarcodeType($code);
        }

        if (! $requested->isValid($code)) {
            abort(422, "'{$code}' cannot be printed as {$requested->label()}. It must be {$requested->requirement()}.");
        }

        return $requested;
    }

    /**
     * Parse the optional `type` query parameter. Empty or "auto" means the
     * product's own symbology; anything unknown is a 422.
     */
    private function requestedType(Request $request): ?BarcodeType
    {
        $value = $request->query('type');

        if ($value === null || $value === '' || $value === 'auto') {
            return null;
        }

        $type = is_string($value) ? BarcodeType::tryFrom($value) : null;

        if ($type === null) {
            abort(422, 'Unknown barcode type. Use one of: auto, ' . implode(', ', BarcodeType::values()) . '.');
        }

        return $type;
    }
}
