<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Builds QR codes for products and storage locations.
 *
 * Products encode either their SKU (works with any scanner and the in-app
 * lookup) or a deep link to the product page (opens the product from a phone
 * camera). Locations encode `LOC:<code>`, or `LOC:#<id>` when the location
 * has no code, so the in-app scanner can tell a bin label from a product.
 */
final class QrCodeService
{
    public const MODE_SKU = 'sku';

    public const MODE_URL = 'url';

    public const MODES = [self::MODE_SKU, self::MODE_URL];

    public const LOCATION_PREFIX = 'LOC:';

    /**
     * Render $payload as an SVG QR code.
     */
    public function svg(string $payload, int $size = 160): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd);

        $svg = (new Writer($renderer))->writeString($payload);

        // Drop the XML declaration so the markup can be inlined in HTML.
        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    /**
     * Render $payload as a data URI for an <img> tag.
     */
    public function dataUri(string $payload, int $size = 160): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($payload, $size));
    }

    public function productPayload(Product $product, string $mode = self::MODE_SKU): string
    {
        return $mode === self::MODE_URL
            ? route('products.show', $product)
            : (string) $product->sku;
    }

    public function locationPayload(ProductLocation $location): string
    {
        $code = trim((string) $location->code);

        return self::LOCATION_PREFIX.($code !== '' ? $code : '#'.$location->id);
    }
}
