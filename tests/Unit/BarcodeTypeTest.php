<?php

namespace Tests\Unit;

use App\Enums\BarcodeType;
use App\Services\BarcodeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BarcodeTypeTest extends TestCase
{
    /**
     * @return array<string, array{BarcodeType, string, bool}>
     */
    public static function validityCases(): array
    {
        return [
            'ean13 valid' => [BarcodeType::EAN_13, '4006381333931', true],
            'ean13 bad check digit' => [BarcodeType::EAN_13, '4006381333932', false],
            'ean13 wrong length' => [BarcodeType::EAN_13, '400638133393', false],
            'ean13 letters' => [BarcodeType::EAN_13, '400638133393A', false],
            'upca valid' => [BarcodeType::UPC_A, '036000291452', true],
            'upca bad check digit' => [BarcodeType::UPC_A, '036000291453', false],
            'upca wrong length' => [BarcodeType::UPC_A, '4006381333931', false],
            'ean8 valid' => [BarcodeType::EAN_8, '96385074', true],
            'ean8 bad check digit' => [BarcodeType::EAN_8, '96385075', false],
            'code39 valid' => [BarcodeType::CODE_39, 'BIN-A1 $/+%.', true],
            'code39 lowercase' => [BarcodeType::CODE_39, 'bin-a1', false],
            'code39 asterisk reserved' => [BarcodeType::CODE_39, 'A*B', false],
            'code128 valid' => [BarcodeType::CODE_128, 'sku-001/a b', true],
            'code128 empty' => [BarcodeType::CODE_128, '', false],
            'code128 non-ascii' => [BarcodeType::CODE_128, 'caf'."\u{00E9}", false],
        ];
    }

    #[DataProvider('validityCases')]
    public function test_validation(BarcodeType $type, string $value, bool $expected): void
    {
        $this->assertSame($expected, $type->isValid($value));
    }

    public function test_detects_ean13_and_upca_and_falls_back_to_code128(): void
    {
        $this->assertSame(BarcodeType::EAN_13, BarcodeType::detect('4006381333931'));
        $this->assertSame(BarcodeType::UPC_A, BarcodeType::detect('036000291452'));
        $this->assertSame(BarcodeType::CODE_128, BarcodeType::detect('4006381333932'));
        $this->assertSame(BarcodeType::CODE_128, BarcodeType::detect('TEST-001'));
        $this->assertSame(BarcodeType::CODE_128, BarcodeType::detect('96385074'));
    }

    public function test_generated_barcodes_are_valid_ean13(): void
    {
        $service = new BarcodeService;

        $this->assertTrue(BarcodeType::EAN_13->isValid($service->generateRandomBarcode()));
        $this->assertTrue(BarcodeType::EAN_13->isValid($service->generateFromSKU('SKU-42')));
    }

    public function test_service_renders_every_type(): void
    {
        $service = new BarcodeService;

        $samples = [
            'code128' => 'TEST-001',
            'ean13' => '4006381333931',
            'upca' => '036000291452',
            'ean8' => '96385074',
            'code39' => 'BIN-A1',
        ];

        foreach (BarcodeType::cases() as $type) {
            $svg = $service->generateSVG($samples[$type->value], 2, 50, $type);
            $this->assertStringContainsString('<svg', $svg, $type->value);
        }

        // Different symbologies draw different bars for the same digits.
        $this->assertNotSame(
            $service->generateSVG('4006381333931', 2, 50, BarcodeType::EAN_13),
            $service->generateSVG('4006381333931', 2, 50, BarcodeType::CODE_128),
        );
    }
}
