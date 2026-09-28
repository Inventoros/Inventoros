<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The approval state of an Order, independent of its fulfilment status.
 *
 * NOT_REQUIRED is what an order gets when its organization does not require
 * sales order approval (the default): there is nothing to decide and nothing
 * waits on it. PENDING only happens when approval is required, and blocks
 * shipping until the order is APPROVED (or REJECTED, which cancels it).
 */
enum OrderApprovalStatus: string
{
    case NOT_REQUIRED = 'not_required';
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
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases()
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::NOT_REQUIRED => 'Not required',
            self::PENDING => 'Pending approval',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
        };
    }
}
