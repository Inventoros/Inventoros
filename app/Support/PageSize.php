<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolves a client-supplied page size or result limit. A missing, empty,
 * non-numeric or below-1 value means "use the default" (as paginate() treats
 * 0 or an empty value), and anything above the cap is capped. Laravel
 * ignores a negative limit(), so the raw value must never reach the query.
 */
final class PageSize
{
    public static function resolve(mixed $value, int $default = 15, int $max = 100): int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            return $default;
        }

        return min((int) $value, $max);
    }
}
