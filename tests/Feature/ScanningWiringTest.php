<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pins the barcode scanning wiring on the count, transfer and order pages.
 *
 * There is no JS test runner, so these read the page source: each page must
 * mount the camera scanner modal and listen for keyboard-wedge scanners, and
 * every scanning string it uses must exist in the English locale.
 */
class ScanningWiringTest extends TestCase
{
    private const PAGES = [
        'Pages/StockAudits/Show.vue',
        'Pages/StockTransfers/Create.vue',
        'Pages/StockTransfers/Show.vue',
        'Pages/Orders/Create.vue',
    ];

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path('resources/js/'.$path));
    }

    public function test_scanning_pages_mount_the_camera_modal_and_the_wedge_listener(): void
    {
        foreach (self::PAGES as $page) {
            $source = $this->source($page);

            $this->assertStringContainsString("import('@/Components/BarcodeScannerModal.vue')", $source, "{$page} does not load the scanner modal.");
            $this->assertMatchesRegularExpression('/<BarcodeScannerModal\b[^>]*@product-found=/s', $source, "{$page} does not handle a scanned product.");
            $this->assertStringContainsString("from '@/composables/useBarcodeWedge'", $source, "{$page} does not import the wedge composable.");
            $this->assertMatchesRegularExpression('/useBarcodeWedge\(/', $source, "{$page} does not listen for keyboard-wedge scans.");
        }
    }

    public function test_the_wedge_composable_cleans_up_its_listener(): void
    {
        $source = $this->source('composables/useBarcodeWedge.js');

        $this->assertStringContainsString("addEventListener('keydown'", $source);
        $this->assertStringContainsString("removeEventListener('keydown'", $source);
        $this->assertStringContainsString("route('barcode.lookup')", $source);
    }

    public function test_audit_scans_count_through_the_existing_count_endpoint(): void
    {
        $this->assertStringContainsString(
            "route('stock-audits.items.count'",
            $this->source('Pages/StockAudits/Show.vue'),
        );
    }

    public function test_every_scanning_string_exists_in_the_english_locale(): void
    {
        $locale = json_decode($this->source('i18n/locales/en.json'), true);
        $this->assertIsArray($locale['scanning'] ?? null, 'en.json has no "scanning" section.');

        $used = [];
        foreach ([...self::PAGES, 'composables/useBarcodeWedge.js'] as $file) {
            preg_match_all("/\bt\('scanning\.([A-Za-z]+)'/", $this->source($file), $matches);
            array_push($used, ...$matches[1]);
        }

        $this->assertNotEmpty($used);
        foreach (array_unique($used) as $key) {
            $this->assertArrayHasKey($key, $locale['scanning'], "scanning.{$key} is missing from en.json.");
        }
    }
}
