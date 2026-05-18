<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle state of an Order.
 *
 * The string values match the existing column shape so this enum can be
 * adopted by callsites incrementally:
 *
 *   - Validation rules: Rule::enum(OrderStatus::class) instead of
 *     'in:pending,processing,shipped,delivered,cancelled'.
 *   - Model casts: $casts['status'] = OrderStatus::class.
 *   - Inertia props: pass OrderStatus::options() instead of a hardcoded
 *     array.
 *
 * Until those adoptions land, equality against the legacy string values
 * still works via $status === OrderStatus::PENDING->value.
 */
enum OrderStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';

    /**
     * Bare string values, suitable for validation 'in:' rules or
     * front-end dropdown payloads.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Label/value pairs for front-end form options.
     *
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
