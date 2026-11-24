<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use Database\Seeders\ScreenshotBrandingSeeder;
use Database\Seeders\ScreenshotProductImagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScreenshotProductImagesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_photos_attach_to_live_products_and_skip_trashed_ones(): void
    {
        config(['filesystems.uploads_disk' => null]);
        Storage::fake('public');
        $organization = Organization::factory()->create(['name' => ScreenshotBrandingSeeder::DEMO_COMPANY]);
        $live = Product::factory()->create(['organization_id' => $organization->id, 'sku' => 'SKU-10001', 'images' => null]);
        $trashed = Product::factory()->create(['organization_id' => $organization->id, 'sku' => 'SKU-10002', 'images' => null]);
        $trashed->delete();

        $this->seed(ScreenshotProductImagesSeeder::class);

        $this->assertSame(['products/demo-sku-10001.jpg'], $live->fresh()->images);
        Storage::disk('public')->assertExists('products/demo-sku-10001.jpg');
        $this->assertEmpty(Product::withTrashed()->withoutGlobalScopes()->find($trashed->id)->images);
        Storage::disk('public')->assertMissing('products/demo-sku-10002.jpg');
    }
}
