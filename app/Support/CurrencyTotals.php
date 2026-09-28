<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Amounts kept apart by currency, never added across currencies.
 *
 * Turns {currency code => amount} (codes in any case; a blank code counts as
 * the base currency) into a list of {currency, amount}: the base currency
 * first and always present, then every other currency with a non-zero
 * amount, alphabetically.
 */
final class CurrencyTotals
{
    /**
     * @param  iterable<string|int|null, mixed>  $amounts
     * @return array<int, array{currency: string, amount: float}>
     */
    public static function list(iterable $amounts, string $base): array
    {
        $base = strtoupper(trim($base));
        $totals = [$base => 0.0];

        foreach ($amounts as $code => $amount) {
            $code = trim((string) $code);
            $code = $code === '' ? $base : strtoupper($code);
            $totals[$code] = round(($totals[$code] ?? 0.0) + (float) $amount, 2);
        }

        uksort($totals, fn (string $a, string $b) => [$a !== $base, $a] <=> [$b !== $base, $b]);

        $rows = [];
        foreach ($totals as $code => $amount) {
            if ($code === $base || $amount != 0.0) {
                $rows[] = ['currency' => $code, 'amount' => $amount];
            }
        }

        return $rows;
    }
}
