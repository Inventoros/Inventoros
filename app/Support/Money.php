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
 * Every operation works at extra precision and rounds the RESULT half away
 * from zero to the cent (commercial rounding), so an input with more than two
 * decimals (0.125 * 3 = 0.375 -> 0.38) is never silently truncated. Inputs
 * are 2-dp in practice (money fields validate `decimal:0,2`), where this is
 * exact.
 */
final class Money
{
    public const SCALE = 2;

    /**
     * Working precision for intermediate results, before rounding to SCALE.
     */
    private const WORK_SCALE = 8;

    /**
     * Normalize any numeric value to a 2-dp decimal string, rounding half up.
     */
    public static function of(int|float|string|null $value): string
    {
        return self::round(self::operand($value));
    }

    /**
     * Sum any number of money values exactly.
     */
    public static function add(int|float|string|null ...$values): string
    {
        $sum = '0';

        foreach ($values as $value) {
            $sum = bcadd($sum, self::operand($value), self::WORK_SCALE);
        }

        return self::round($sum);
    }

    /**
     * Multiply a money value by a (quantity) factor, rounded half up to the cent.
     */
    public static function multiply(int|float|string|null $amount, int|float|string|null $factor): string
    {
        return self::round(bcmul(self::operand($amount), self::operand($factor), self::WORK_SCALE));
    }

    /**
     * Subtract any number of money values from the first, exactly. The result
     * may be negative; callers that must not go below zero check isNegative().
     */
    public static function subtract(int|float|string|null $from, int|float|string|null ...$values): string
    {
        $result = self::operand($from);

        foreach ($values as $value) {
            $result = bcsub($result, self::operand($value), self::WORK_SCALE);
        }

        return self::round($result);
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
        $raw = bcdiv(bcmul(self::operand($amount), self::operand($percent), self::WORK_SCALE), '100', self::WORK_SCALE);

        return self::round($raw);
    }

    /**
     * Round an arbitrary-precision decimal string half away from zero to 2 dp.
     */
    public static function round(string $value): string
    {
        $half = str_starts_with(ltrim($value), '-') ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $half, self::WORK_SCALE), '0', self::SCALE);
    }

    /**
     * A value bcmath accepts: null is zero, and a float is written out in
     * plain decimal notation ((string) 1.0E-5 is "1.0E-5", which bcmath
     * rejects).
     */
    private static function operand(int|float|string|null $value): string
    {
        if ($value === null) {
            return '0';
        }

        if (is_float($value)) {
            $string = (string) $value;

            return str_contains($string, 'E')
                ? rtrim(rtrim(sprintf('%.'.self::WORK_SCALE.'F', $value), '0'), '.')
                : $string;
        }

        return trim((string) $value);
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
