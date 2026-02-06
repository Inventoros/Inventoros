<?php

namespace Tests\Unit;

use App\Services\BarcodeService;
use Tests\TestCase;

class BarcodeServiceTest extends TestCase
{
    protected BarcodeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BarcodeService();
    }

    public function test_generate_random_barcode_returns_13_digits(): void
    {
        $barcode = $this->service->generateRandomBarcode();

        $this->assertSame(13, strlen($barcode));
        $this->assertTrue(ctype_digit($barcode));
    }

    public function test_generate_random_barcode_has_valid_check_digit(): void
    {
        $barcode = $this->service->generateRandomBarcode();

        $this->assertTrue($this->service->validateEAN13($barcode));
    }

    public function test_validate_ean13_accepts_valid_barcode(): void
    {
        // Known valid EAN-13: 4006381333931
        $this->assertTrue($this->service->validateEAN13('4006381333931'));
    }

    public function test_validate_ean13_rejects_wrong_check_digit(): void
    {
        // Same barcode but with wrong check digit
        $this->assertFalse($this->service->validateEAN13('4006381333932'));
    }

    public function test_validate_ean13_rejects_non_numeric(): void
    {
        $this->assertFalse($this->service->validateEAN13('400638133393a'));
    }

    public function test_validate_ean13_rejects_wrong_length(): void
    {
        $this->assertFalse($this->service->validateEAN13('12345'));
        $this->assertFalse($this->service->validateEAN13('12345678901234'));
    }

    public function test_validate_ean13_rejects_empty_string(): void
    {
        $this->assertFalse($this->service->validateEAN13(''));
    }

    public function test_generate_from_sku_returns_valid_ean13(): void
    {
        $barcode = $this->service->generateFromSKU('ELE-000123');

        $this->assertSame(13, strlen($barcode));
        $this->assertTrue(ctype_digit($barcode));
        $this->assertTrue($this->service->validateEAN13($barcode));
    }

    public function test_generate_from_sku_pads_short_numerics(): void
    {
        $barcode = $this->service->generateFromSKU('ABC-1');

        $this->assertSame(13, strlen($barcode));
        // Extracted numeric "1" padded to 12 digits: "000000000001"
        $this->assertStringStartsWith('000000000001', $barcode);
        $this->assertTrue($this->service->validateEAN13($barcode));
    }

    public function test_generate_from_sku_truncates_long_numerics(): void
    {
        $barcode = $this->service->generateFromSKU('1234567890123456');

        $this->assertSame(13, strlen($barcode));
        $this->assertTrue($this->service->validateEAN13($barcode));
    }

    public function test_generate_from_sku_handles_pure_alpha_sku(): void
    {
        $barcode = $this->service->generateFromSKU('ABCDEF');

        $this->assertSame(13, strlen($barcode));
        // No digits extracted, so 12 zeros + check digit
        $this->assertStringStartsWith('000000000000', $barcode);
        $this->assertTrue($this->service->validateEAN13($barcode));
    }

    public function test_generate_png_returns_base64_string(): void
    {
        $png = $this->service->generatePNG('12345');

        $this->assertIsString($png);
        $this->assertNotEmpty($png);
        // Verify it's valid base64
        $decoded = base64_decode($png, true);
        $this->assertNotFalse($decoded);
    }

    public function test_generate_html_returns_html_string(): void
    {
        $html = $this->service->generateHTML('12345');

        $this->assertIsString($html);
        $this->assertStringContainsString('div', $html);
    }

    public function test_generate_svg_returns_svg_string(): void
    {
        $svg = $this->service->generateSVG('12345');

        $this->assertIsString($svg);
        $this->assertStringContainsString('svg', $svg);
    }

    public function test_generate_png_accepts_custom_dimensions(): void
    {
        $png = $this->service->generatePNG('12345', 3, 100);

        $this->assertIsString($png);
        $this->assertNotEmpty($png);
    }

    public function test_multiple_random_barcodes_are_different(): void
    {
        $barcodes = [];
        for ($i = 0; $i < 10; $i++) {
            $barcodes[] = $this->service->generateRandomBarcode();
        }

        // At least some should be unique (statistically all will be)
        $this->assertGreaterThan(1, count(array_unique($barcodes)));
    }
}
