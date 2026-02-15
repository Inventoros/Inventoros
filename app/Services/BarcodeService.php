<?php

declare(strict_types=1);

namespace App\Services;

use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorHTML;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Service for generating and validating barcodes.
 *
 * Supports CODE_128 barcode format and EAN-13 validation
 * with multiple output formats (PNG, HTML, SVG).
 */
final class BarcodeService
{
    public const DEFAULT_WIDTH_FACTOR = 2;
    public const DEFAULT_HEIGHT = 50;
    public const EAN13_LENGTH = 13;
    public const EAN13_DATA_LENGTH = 12;
    /**
     * Generate barcode as PNG image.
     *
     * @param string $code The barcode content
     * @param int $widthFactor Width multiplier (default: 2)
     * @param int $height Height in pixels (default: 50)
     * @return string Base64-encoded PNG image data
     */
    public function generatePNG(string $code, int $widthFactor = 2, int $height = 50): string
    {
        $generator = new BarcodeGeneratorPNG();
        return base64_encode($generator->getBarcode($code, $generator::TYPE_CODE_128, $widthFactor, $height));
    }

    /**
     * Generate barcode as HTML.
     *
     * @param string $code The barcode content
     * @param int $widthFactor Width multiplier (default: 2)
     * @param int $height Height in pixels (default: 50)
     * @return string HTML representation of the barcode
     */
    public function generateHTML(string $code, int $widthFactor = 2, int $height = 50): string
    {
        $generator = new BarcodeGeneratorHTML();
        return $generator->getBarcode($code, $generator::TYPE_CODE_128, $widthFactor, $height);
    }

    /**
     * Generate barcode as SVG.
     *
     * @param string $code The barcode content
     * @param int $widthFactor Width multiplier (default: 2)
     * @param int $height Height in pixels (default: 50)
     * @return string SVG markup for the barcode
     */
    public function generateSVG(string $code, int $widthFactor = 2, int $height = 50): string
    {
        $generator = new BarcodeGeneratorSVG();
        return $generator->getBarcode($code, $generator::TYPE_CODE_128, $widthFactor, $height);
    }

    /**
     * Generate a random barcode number.
     *
     * Generates a valid EAN-13 barcode with proper check digit.
     *
     * @param int $length Total length including check digit (default: 13)
     * @return string A valid EAN-13 barcode
     */
    public function generateRandomBarcode(int $length = 13): string
    {
        $code = '';
        for ($i = 0; $i < $length - 1; $i++) {
            $code .= mt_rand(0, 9);
        }

        $checkDigit = $this->calculateEAN13CheckDigit($code);
        return $code . $checkDigit;
    }

    /**
     * Calculate EAN-13 check digit.
     *
     * @param string $code The first 12 digits of the barcode
     * @return int The calculated check digit (0-9)
     */
    private function calculateEAN13CheckDigit(string $code): int
    {
        $sum = 0;
        for ($i = 0; $i < strlen($code); $i++) {
            $digit = (int)$code[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $checkDigit = (10 - ($sum % 10)) % 10;
        return $checkDigit;
    }

    /**
     * Generate barcode from SKU.
     *
     * Converts SKU to numeric format and appends EAN-13 check digit.
     *
     * @param string $sku The product SKU to convert
     * @return string A valid EAN-13 barcode
     */
    public function generateFromSKU(string $sku): string
    {
        $numeric = preg_replace('/[^0-9]/', '', $sku);

        if (strlen($numeric) < 12) {
            $numeric = str_pad($numeric, 12, '0', STR_PAD_LEFT);
        } else {
            $numeric = substr($numeric, 0, 12);
        }

        return $numeric . $this->calculateEAN13CheckDigit($numeric);
    }

    /**
     * Validate EAN-13 barcode.
     *
     * @param string $barcode The barcode to validate
     * @return bool True if barcode is a valid EAN-13
     */
    public function validateEAN13(string $barcode): bool
    {
        if (strlen($barcode) !== 13 || !ctype_digit($barcode)) {
            return false;
        }

        $code = substr($barcode, 0, 12);
        $checkDigit = (int)substr($barcode, 12, 1);

        return $this->calculateEAN13CheckDigit($code) === $checkDigit;
    }
}
