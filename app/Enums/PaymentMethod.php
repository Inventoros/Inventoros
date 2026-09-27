<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a customer paid (or was refunded) for an order.
 */
enum PaymentMethod: string
{
    case CASH = 'cash';
    case CARD = 'card';
    case BANK_TRANSFER = 'bank_transfer';
    case CHEQUE = 'cheque';
    case OTHER = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash',
            self::CARD => 'Card',
            self::BANK_TRANSFER => 'Bank transfer',
            self::CHEQUE => 'Cheque',
            self::OTHER => 'Other',
        };
    }
}
