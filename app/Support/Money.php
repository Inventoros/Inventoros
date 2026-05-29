<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Exact fixed-point money arithmetic, backed by bcmath.
 *
 * Money columns are stored as `decimal:2` (exact in the database, surfaced as
 * 2-dp strings by Eloquent), but PHP arithmetic on those values coerces them to
 * binary floats — so `0.1 + 0.2`, repeated sums, and `qty * unit_price` all
 * accumulate rounding error. These helpers keep every intermediate value as a
 * 2-dp decimal string so totals are exact and round-trip cleanly through the
 * decimal cast.
 *
 * Inputs are already 2-dp in practice (validated numeric / decimal columns), so
 * bcmath's truncation at SCALE is exact for them.
 */
final class Money
{
    public const SCALE = 2;

    /**
     * Normalize any numeric value to a 2-dp decimal string.
     */
    public static function of(int|float|string|null $value): string
    {
        return bcadd((string) ($value ?? 0), '0', self::SCALE);
    }

    /**
     * Sum any number of money values exactly.
     */
    public static function add(int|float|string|null ...$values): string
    {
        $sum = '0';

        foreach ($values as $value) {
            $sum = bcadd($sum, (string) ($value ?? 0), self::SCALE);
        }

        return self::of($sum);
    }

    /**
     * Multiply a money value by a (quantity) factor exactly.
     */
    public static function multiply(int|float|string|null $amount, int|float|string|null $factor): string
    {
        return bcmul((string) ($amount ?? 0), (string) ($factor ?? 0), self::SCALE);
    }
}
