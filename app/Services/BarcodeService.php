<?php

namespace App\Services;

use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorHTML;
use Picqer\Barcode\BarcodeGeneratorSVG;

class BarcodeService
{
    /**
     * Generate barcode as PNG image
     */
    public function generatePNG(string $code, int $widthFactor = 2, int $height = 50): string
    {
        $generator = new BarcodeGeneratorPNG();
        return base64_encode($generator->getBarcode($code, $generator::TYPE_CODE_128, $widthFactor, $height));
    }

    /**
     * Generate barcode as HTML
     */
    public function generateHTML(string $code, int $widthFactor = 2, int $height = 50): string
    {
        $generator = new BarcodeGeneratorHTML();
        return $generator->getBarcode($code, $generator::TYPE_CODE_128, $widthFactor, $height);
    }

    /**
     * Generate barcode as SVG
     */
    public function generateSVG(string $code, int $widthFactor = 2, int $height = 50): string
    {
        $generator = new BarcodeGeneratorSVG();
        return $generator->getBarcode($code, $generator::TYPE_CODE_128, $widthFactor, $height);
    }

    /**
     * Generate a random barcode number
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
     * Calculate EAN-13 check digit
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
     * Generate barcode from SKU
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
     * Validate EAN-13 barcode
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
