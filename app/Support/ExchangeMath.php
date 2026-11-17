<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact currency conversion with bcmath.
 *
 * Rates are decimal strings with up to RATE_SCALE decimals (the columns are
 * decimal(20,10)); amounts are decimal strings. A converted amount is
 * rounded half away from zero to the target currency's minor units (ISO
 * 4217: two for most, none for JPY and KRW; see config('currencies')), then
 * written with two decimals, the scale of every money column.
 */
final class ExchangeMath
{
    public const RATE_SCALE = 10;

    /**
     * The largest rate accepted: ten integer digits (decimal(20,10)).
     */
    public const MAX_RATE = '9999999999.9999999999';

    private const WORK_SCALE = 16;

    /**
     * $amount in the source currency, converted at $rate (1 source = $rate
     * target) and rounded to $targetCurrency's minor units.
     */
    public static function convert(int|float|string|null $amount, string $rate, string $targetCurrency): string
    {
        $product = bcmul(self::operand($amount), self::rate($rate), self::WORK_SCALE);

        return self::roundTo($product, self::decimals($targetCurrency));
    }

    /**
     * A validated, normalized rate string with up to RATE_SCALE decimals and
     * no trailing zeros ("392.4500000000" -> "392.45").
     *
     * @throws InvalidArgumentException when the value is not a positive decimal in range
     */
    public static function rate(int|float|string $value): string
    {
        $string = is_float($value)
            ? rtrim(rtrim(sprintf('%.'.self::RATE_SCALE.'F', $value), '0'), '.')
            : trim((string) $value);

        if (preg_match('/^\d{1,10}(\.\d+)?\z/', $string) !== 1) {
            throw new InvalidArgumentException("\"{$string}\" is not a valid exchange rate.");
        }

        // More decimals than the column holds are rounded half up, not cut.
        $normalized = strlen(substr((string) strrchr($string, '.'), 1)) > self::RATE_SCALE
            ? bcadd($string, '0.'.str_repeat('0', self::RATE_SCALE).'5', self::RATE_SCALE)
            : bcadd($string, '0', self::RATE_SCALE);

        if (bccomp($normalized, '0', self::RATE_SCALE) <= 0) {
            throw new InvalidArgumentException('An exchange rate must be greater than zero.');
        }

        return self::trim($normalized);
    }

    /**
     * Whether $value is a rate rate() accepts.
     */
    public static function isValidRate(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return false;
        }

        try {
            self::rate($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Whether two rates are the same number.
     */
    public static function sameRate(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return bccomp(self::rate($a), self::rate($b), self::RATE_SCALE) === 0;
    }

    /**
     * The minor units (decimal places) of an ISO 4217 currency.
     */
    public static function decimals(string $currency): int
    {
        $decimals = config('currencies.supported.'.strtoupper(trim($currency)).'.decimals');

        return is_int($decimals) && $decimals >= 0 && $decimals <= 2 ? $decimals : 2;
    }

    /**
     * Round half away from zero to $decimals places, written with two
     * decimals (the money column scale).
     */
    private static function roundTo(string $value, int $decimals): string
    {
        $half = '0.'.str_repeat('0', $decimals).'5';
        $sign = str_starts_with(ltrim($value), '-') ? '-' : '';
        $rounded = bcadd($value, $sign.$half, $decimals);

        return bcadd($rounded, '0', 2);
    }

    private static function operand(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.8F', $value), '0'), '.') ?: '0';
        }

        return trim((string) $value);
    }

    private static function trim(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
