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

    /**
     * Subtract any number of money values from the first, exactly. The result
     * may be negative; callers that must not go below zero check isNegative().
     */
    public static function subtract(int|float|string|null $from, int|float|string|null ...$values): string
    {
        $result = self::of($from);

        foreach ($values as $value) {
            $result = bcsub($result, (string) ($value ?? 0), self::SCALE);
        }

        return self::of($result);
    }

    /**
     * A percentage of a money value, rounded half up to the cent.
     *
     * Unlike add/multiply (whose 2-dp inputs stay exact), a percentage can land
     * between cents (15% of 10.05 = 1.5075), and bcmath would truncate it. The
     * product is computed at extra precision and rounded half away from zero,
     * the usual commercial rounding for a discount line.
     */
    public static function percentOf(int|float|string|null $amount, int|float|string|null $percent): string
    {
        $raw = bcdiv(bcmul((string) ($amount ?? 0), (string) ($percent ?? 0), 8), '100', 8);

        return self::round($raw);
    }

    /**
     * Round an arbitrary-precision decimal string half away from zero to 2 dp.
     */
    public static function round(string $value): string
    {
        $half = str_starts_with(ltrim($value), '-') ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $half, 8), '0', self::SCALE);
    }

    /**
     * Compare two money values: -1, 0 or 1.
     */
    public static function compare(int|float|string|null $a, int|float|string|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isNegative(int|float|string|null $value): bool
    {
        return self::compare($value, 0) < 0;
    }

    public static function isZero(int|float|string|null $value): bool
    {
        return self::compare($value, 0) === 0;
    }

    public static function max(int|float|string|null $a, int|float|string|null $b): string
    {
        return self::compare($a, $b) >= 0 ? self::of($a) : self::of($b);
    }

    public static function min(int|float|string|null $a, int|float|string|null $b): string
    {
        return self::compare($a, $b) <= 0 ? self::of($a) : self::of($b);
    }
}
