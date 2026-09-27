<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Services\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * QR codes for products (SKU or deep link) and storage locations
 * (location code), shown in the app and printed as labels.
 */
class QrCodeController extends Controller
{
    public function __construct(private readonly QrCodeService $qr) {}

    /**
     * QR code image for a product, as a data URI.
     */
    public function generate(Request $request, Product $product): JsonResponse
    {
        $this->assertSameOrganization($request, $product->organization_id);

        $mode = $this->mode($request);
        $payload = $this->qr->productPayload($product, $mode);

        return response()->json([
            'qr' => $this->qr->dataUri($payload, 200),
            'payload' => $payload,
            'mode' => $mode,
        ]);
    }

    /**
     * Printable QR label for one product.
     */
    public function print(Request $request, Product $product): Response
    {
        $this->assertSameOrganization($request, $product->organization_id);

        $mode = $this->mode($request);

        return $this->labels(
            [$this->productLabel($product, $mode)],
            'QR Label - '.$product->name,
            route('products.qr.print', $product),
            $mode,
        );
    }

    /**
     * Printable QR labels for several products.
     */
    public function bulkPrint(Request $request): Response
    {
        $ids = $this->ids($request);
        $mode = $this->mode($request);

        $products = Product::whereIn('id', $ids)
            ->where('organization_id', $request->user()->organization_id)
            ->get();

        return $this->labels(
            $products->map(fn (Product $p) => $this->productLabel($p, $mode))->all(),
            'Print QR Labels ('.$products->count().' items)',
            route('products.qr.bulk-print'),
            $mode,
            implode(',', $products->pluck('id')->all()),
        );
    }

    /**
     * Printable QR label for one storage location.
     */
    public function printLocation(Request $request, ProductLocation $location): Response
    {
        $this->assertSameOrganization($request, $location->organization_id);

        return $this->labels([$this->locationLabel($location)], 'QR Label - '.$location->name);
    }

    /**
     * Printable QR labels for several storage locations. With no ids, prints
     * every active location in the organization.
     */
    public function bulkPrintLocations(Request $request): Response
    {
        $query = ProductLocation::where('organization_id', $request->user()->organization_id)
            ->orderBy('name');

        if ($request->filled('ids')) {
            $query->whereIn('id', $this->ids($request));
        } else {
            $query->where('is_active', true);
        }

        $locations = $query->get();

        return $this->labels(
            $locations->map(fn (ProductLocation $l) => $this->locationLabel($l))->all(),
            'Print Location Labels ('.$locations->count().' items)',
        );
    }

    /**
     * @return array{title: string, subtitle: string, payload: string, svg: string}
     */
    private function productLabel(Product $product, string $mode): array
    {
        $payload = $this->qr->productPayload($product, $mode);

        return [
            'title' => $product->name,
            'subtitle' => 'SKU: '.$product->sku,
            'payload' => $payload,
            'svg' => $this->qr->svg($payload, 120),
        ];
    }

    /**
     * @return array{title: string, subtitle: string, payload: string, svg: string}
     */
    private function locationLabel(ProductLocation $location): array
    {
        $payload = $this->qr->locationPayload($location);
        $position = collect([
            $location->aisle ? 'Aisle '.$location->aisle : null,
            $location->shelf ? 'Shelf '.$location->shelf : null,
            $location->bin ? 'Bin '.$location->bin : null,
        ])->filter()->implode(' / ');

        return [
            'title' => $location->name,
            'subtitle' => $position !== '' ? $position : (string) ($location->code ?? ''),
            'payload' => $payload,
            'svg' => $this->qr->svg($payload, 120),
        ];
    }

    /**
     * @param  array<int, array{title: string, subtitle: string, payload: string, svg: string}>  $labels
     */
    private function labels(array $labels, string $title, ?string $modeAction = null, ?string $mode = null, ?string $ids = null): Response
    {
        $html = view('qr.labels', [
            'labels' => $labels,
            'title' => $title,
            'modeAction' => $modeAction,
            'mode' => $mode,
            'ids' => $ids,
        ])->render();

        return response($html, 200)->header('Content-Type', 'text/html');
    }

    private function mode(Request $request): string
    {
        $mode = $request->query('mode', QrCodeService::MODE_SKU);

        if (! is_string($mode) || ! in_array($mode, QrCodeService::MODES, true)) {
            abort(422, 'Unknown QR mode. Use one of: '.implode(', ', QrCodeService::MODES).'.');
        }

        return $mode;
    }

    /**
     * @return array<int, int>
     */
    private function ids(Request $request): array
    {
        $ids = array_values(array_filter(
            array_map('intval', explode(',', (string) $request->query('ids', ''))),
            fn (int $id) => $id > 0,
        ));

        if ($ids === []) {
            abort(400, 'No IDs provided');
        }

        return $ids;
    }

    private function assertSameOrganization(Request $request, ?int $organizationId): void
    {
        if ($organizationId !== $request->user()->organization_id) {
            abort(403);
        }
    }
}
