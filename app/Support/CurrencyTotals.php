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

    /**
     * Several figures per currency: the rows (objects or arrays with a
     * `currency` key) are merged by upper-cased code and each of $fields is
     * summed, money $fields rounded to the cent, the rest as integers
     * ($integerFields). Same order and filtering as list(): the base
     * currency first and always present, others only when a money field is
     * non-zero.
     *
     * @param  iterable<int, object|array<string, mixed>>  $rows
     * @param  array<int, string>  $fields  money fields
     * @param  array<int, string>  $integerFields  count fields
     * @return array<int, array<string, mixed>>
     */
    public static function breakdown(iterable $rows, string $base, array $fields, array $integerFields = []): array
    {
        $base = strtoupper(trim($base));
        $empty = fn (string $code) => ['currency' => $code]
            + array_fill_keys($integerFields, 0)
            + array_fill_keys($fields, 0.0);
        $totals = [$base => $empty($base)];

        foreach ($rows as $row) {
            $row = (array) $row;
            $code = trim((string) ($row['currency'] ?? ''));
            $code = $code === '' ? $base : strtoupper($code);
            $totals[$code] ??= $empty($code);

            foreach ($integerFields as $field) {
                $totals[$code][$field] += (int) ($row[$field] ?? 0);
            }
            foreach ($fields as $field) {
                $totals[$code][$field] = round($totals[$code][$field] + (float) ($row[$field] ?? 0), 2);
            }
        }

        uksort($totals, fn (string $a, string $b) => [$a !== $base, $a] <=> [$b !== $base, $b]);

        return array_values(array_filter(
            $totals,
            fn (array $t) => $t['currency'] === $base
                || array_filter($fields, fn (string $f) => $t[$f] != 0.0) !== [],
        ));
    }
}
