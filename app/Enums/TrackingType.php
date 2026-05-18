<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How stock is tracked for a given Product.
 *
 * Values match the existing tracking_type column shape so this enum
 * can be adopted by callsites incrementally.
 */
enum TrackingType: string
{
    case NONE = 'none';
    case BATCH = 'batch';
    case SERIAL = 'serial';

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
