<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Money;

/**
 * An order's payment position, derived from its total and the net of its
 * non-voided payments and refunds.
 */
enum PaymentStatus: string
{
    case UNPAID = 'unpaid';
    case PARTIAL = 'partial';
    case PAID = 'paid';
    case OVERPAID = 'overpaid';
    case REFUNDED = 'refunded';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Derive the status.
     *
     *  - refunded: something was refunded and nothing is left paid;
     *  - unpaid:   nothing paid on an order that costs something;
     *  - partial:  paid, but less than the total;
     *  - paid:     paid exactly the total (a zero-total order is paid);
     *  - overpaid: paid more than the total.
     */
    public static function derive(string $total, string $netPaid, string $refunded): self
    {
        if (Money::compare($refunded, 0) > 0 && Money::compare($netPaid, 0) <= 0) {
            return self::REFUNDED;
        }

        $cmp = Money::compare($netPaid, $total);

        if ($cmp > 0) {
            return self::OVERPAID;
        }

        if ($cmp === 0) {
            return self::PAID;
        }

        return Money::compare($netPaid, 0) > 0 ? self::PARTIAL : self::UNPAID;
    }
}
