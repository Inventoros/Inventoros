<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Services\FileStorageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Run after ScreenshotBrandingSeeder, only for the README screenshots.
 *
 * Gives the demo products their photos and plain descriptions (one photo per SKU in
 * database/seeders/screenshot-assets/products), stored the way an upload
 * is: on the uploads disk, with the path in `images` and its disk in
 * `image_disks`. The E2E suite never runs it.
 */
class ScreenshotProductImagesSeeder extends Seeder
{
    public const ASSET_DIR = __DIR__.'/screenshot-assets/products';

    /** Plain descriptions in place of the fixture's placeholder text. */
    private const DESCRIPTIONS = [
        'SKU-10001' => 'Slim rechargeable keyboard, pairs with up to three devices.',
        'SKU-10002' => 'HDMI 4K, two USB-A, SD and microSD, 100 W pass-through charging.',
        'SKU-10003' => 'IPS panel with height-adjustable stand and USB-C input.',
        'SKU-10004' => 'Gel wrist rest with a non-slip rubber base.',
        'SKU-10005' => 'Three refillable metal pens, black and blue ink.',
        'SKU-10006' => '80 gsm multipurpose paper for laser and inkjet printers.',
        'SKU-10007' => 'Two-tier desk riser with a separate keyboard deck.',
        'SKU-10008' => 'Breathable mesh back with adjustable lumbar support.',
        'SKU-10009' => 'Four adjustable steel shelves, 175 kg per shelf.',
        'SKU-10010' => 'Double-wall box, 60 x 40 x 40 cm, shipped flat.',
        'SKU-10011' => 'Small-cell protective wrap, 50 cm wide.',
        'SKU-10012' => 'Clear acrylic tape, 48 mm x 66 m per roll.',
        'SKU-10013' => 'Full HD webcam with built-in microphone and privacy clip.',
        'SKU-10014' => 'Adjustable aluminium stand for 10 to 17 inch laptops.',
        'SKU-10015' => 'Magnetic dry-erase board with aluminium frame.',
        'PKG-LBL-46' => 'Direct thermal labels, 500 per roll, for 4x6 shipping printers.',
    ];

    public function run(): void
    {
        $orgId = Organization::where('name', ScreenshotBrandingSeeder::DEMO_COMPANY)->value('id');
        if ($orgId === null || ! is_dir(self::ASSET_DIR)) {
            return;
        }

        $disk = app(FileStorageService::class)->uploadsDisk('public');
        $attached = 0;

        foreach (glob(self::ASSET_DIR.'/*.jpg') ?: [] as $file) {
            $sku = pathinfo($file, PATHINFO_FILENAME);
            // withoutGlobalScopes() also lifts the soft-delete scope, so
            // exclude trashed products by hand.
            $product = Product::withoutGlobalScopes()->where('organization_id', $orgId)->where('sku', $sku)
                ->whereNull((new Product)->getQualifiedDeletedAtColumn())->first();
            if ($product === null) {
                continue;
            }

            $path = 'products/demo-'.strtolower($sku).'.jpg';
            if (! Storage::disk($disk)->put($path, (string) file_get_contents($file))) {
                $this->command?->warn("Could not store the screenshot photo for {$sku}; skipped.");

                continue;
            }
            $product->forceFill([
                'images' => [$path],
                'image_disks' => [$path => $disk],
                'thumbnail' => $path,
            ] + (isset(self::DESCRIPTIONS[$sku]) ? ['description' => self::DESCRIPTIONS[$sku]] : []))->saveQuietly();
            $attached++;
        }

        $this->command?->info("Screenshot product photos attached: {$attached}.");
    }
}
