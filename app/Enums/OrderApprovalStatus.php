<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The approval state of an Order, independent of its fulfilment status.
 *
 * Values match the existing approval_status column shape so this enum
 * can be adopted by callsites incrementally.
 */
enum OrderApprovalStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

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
            fn (self $c) => ['value' => $c->value, 'label' => ucfirst($c->value)],
            self::cases()
        );
    }
}
