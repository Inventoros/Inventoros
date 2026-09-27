<?php

declare(strict_types=1);

namespace App\Enums;

use Picqer\Barcode\BarcodeGenerator;

/**
 * Linear barcode symbologies a product barcode can be printed as.
 *
 * Stored in `products.barcode_type`. Null there means "detect": a valid
 * EAN-13 or UPC-A value prints as that retail symbology, anything else as
 * Code 128.
 */
enum BarcodeType: string
{
    case CODE_128 = 'code128';
    case EAN_13 = 'ean13';
    case UPC_A = 'upca';
    case EAN_8 = 'ean8';
    case CODE_39 = 'code39';

    public function label(): string
    {
        return match ($this) {
            self::CODE_128 => 'Code 128',
            self::EAN_13 => 'EAN-13',
            self::UPC_A => 'UPC-A',
            self::EAN_8 => 'EAN-8',
            self::CODE_39 => 'Code 39',
        };
    }

    /**
     * The picqer generator type constant for this symbology.
     */
    public function generatorType(): string
    {
        return match ($this) {
            self::CODE_128 => BarcodeGenerator::TYPE_CODE_128,
            self::EAN_13 => BarcodeGenerator::TYPE_EAN_13,
            self::UPC_A => BarcodeGenerator::TYPE_UPC_A,
            self::EAN_8 => BarcodeGenerator::TYPE_EAN_8,
            self::CODE_39 => BarcodeGenerator::TYPE_CODE_39,
        };
    }

    /**
     * Human-readable rule shown when a value does not fit this symbology.
     */
    public function requirement(): string
    {
        return match ($this) {
            self::CODE_128 => 'printable ASCII characters (up to 80)',
            self::EAN_13 => '13 digits with a valid check digit',
            self::UPC_A => '12 digits with a valid check digit',
            self::EAN_8 => '8 digits with a valid check digit',
            self::CODE_39 => 'uppercase letters, digits, spaces and - . $ / + % (up to 43)',
        };
    }

    /**
     * Whether $value can be encoded in this symbology. Numeric retail codes
     * must carry their full length including a correct check digit, so a
     * mistyped code is rejected instead of silently getting a new digit.
     */
    public function isValid(string $value): bool
    {
        return match ($this) {
            self::CODE_128 => $value !== '' && strlen($value) <= 80 && preg_match('/^[\x20-\x7E]+$/', $value) === 1,
            self::EAN_13 => self::isGtin($value, 13),
            self::UPC_A => self::isGtin($value, 12),
            self::EAN_8 => self::isGtin($value, 8),
            self::CODE_39 => strlen($value) <= 43 && preg_match('/^[0-9A-Z\-. $\/+%]+$/', $value) === 1,
        };
    }

    /**
     * Pick a symbology for a value with no explicit type: valid EAN-13 and
     * UPC-A codes keep their retail symbology, everything else is Code 128.
     */
    public static function detect(string $value): self
    {
        if (self::EAN_13->isValid($value)) {
            return self::EAN_13;
        }

        if (self::UPC_A->isValid($value)) {
            return self::UPC_A;
        }

        return self::CODE_128;
    }

    /**
     * GS1 mod-10 check digit for the data digits (everything but the check
     * digit). Weights alternate 3,1 starting from the rightmost data digit,
     * which covers EAN-8, UPC-A and EAN-13 alike.
     */
    public static function gtinCheckDigit(string $data): int
    {
        $sum = 0;
        $digits = strrev($data);
        for ($i = 0, $n = strlen($digits); $i < $n; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10;
    }

    private static function isGtin(string $value, int $length): bool
    {
        if (strlen($value) !== $length || ! ctype_digit($value)) {
            return false;
        }

        return self::gtinCheckDigit(substr($value, 0, -1)) === (int) substr($value, -1);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases()
        );
    }
}
