<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a line or order discount is expressed: a percentage of the amount it
 * applies to, or a fixed money amount off it.
 */
enum DiscountType: string
{
    case PERCENT = 'percent';
    case FIXED = 'fixed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
