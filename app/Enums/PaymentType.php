<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A payment row is money received; a refund row is money given back and
 * counts against what was paid.
 */
enum PaymentType: string
{
    case PAYMENT = 'payment';
    case REFUND = 'refund';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
