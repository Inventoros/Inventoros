<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProductImageController extends Controller
{
    public function __invoke(Request $request, Product $product, int $index): StreamedResponse
    {
        abort_unless((int) $product->organization_id === (int) $request->user()->organization_id, 404);
        $path = $product->images[$index] ?? null;
        abort_unless($path, 404);
        $disk = Storage::disk($product->image_disks[$path] ?? 'public');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
