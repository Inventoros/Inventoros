<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Per-line tax from a tax rate, for any document made of priced lines (sales
 * orders today; purchase orders can use the same rules).
 *
 * The rules, in order:
 *
 *  1. A line's net is its gross less its own line discount.
 *  2. A document-level discount comes off the merchandise before tax. It is
 *     shared across ALL lines in proportion to their nets (Money::allocate,
 *     in line order), so each line's taxable amount is its net less its share.
 *     This is the same split refunds use (ReturnOrderService::paidLineNets).
 *  3. Lines with a rate are grouped by rate. Each group's tax is computed once
 *     on the group's total taxable amount and rounded to the cent, then split
 *     back across the group's lines in proportion to their taxable amounts.
 *     The document's tax per rate is therefore exactly rate x taxable at that
 *     rate, and the line taxes always add up to it.
 *  4. A line without a rate (null, not 0) keeps the tax amount it was given,
 *     unchanged: imported or API lines that carry an amount, or no tax at all.
 *
 * A rate of 0 is a real rate (zero-rated or exempt goods), not "no rate".
 * Rounding is half away from zero to the cent, as everywhere in Money.
 */
final class LineTax
{
    /**
     * Decimal places a stored rate keeps (decimal(6,3) columns).
     */
    public const RATE_SCALE = 3;

    /**
     * A rate as a fixed 3-dp string, or null when there is no rate.
     */
    public static function rate(mixed $rate): ?string
    {
        if ($rate === null || $rate === '' || ! is_numeric($rate)) {
            return null;
        }

        $value = is_float($rate) ? number_format($rate, self::RATE_SCALE + 2, '.', '') : trim((string) $rate);
        $half = str_starts_with($value, '-') ? '-0.0005' : '0.0005';

        return bcadd(bcadd($value, $half, 8), '0', self::RATE_SCALE);
    }

    /**
     * Whether any of the lines is taxed by rate.
     *
     * @param  iterable<array{tax_rate?: mixed}>  $lines
     */
    public static function anyRated(iterable $lines): bool
    {
        foreach ($lines as $line) {
            if (self::rate($line['tax_rate'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each line's taxable amount and tax.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, array{net: mixed, tax_rate?: mixed, tax?: mixed}>  $lines  in a stable order (the order shares are rounded in)
     * @param  mixed  $documentDiscount  the document-level discount amount, applied after line discounts
     * @return array<TKey, array{taxable: string, tax: string, tax_rate: string|null}>
     */
    public static function compute(array $lines, mixed $documentDiscount = 0): array
    {
        $nets = array_map(fn (array $line): string => Money::max(Money::of($line['net'] ?? 0), 0), $lines);
        $merchandise = Money::add(...array_values($nets));
        $discount = Money::min(Money::max(Money::of($documentDiscount), 0), $merchandise);
        $taxables = Money::allocate(Money::subtract($merchandise, $discount), $nets);

        $result = [];
        $groups = [];
        foreach ($lines as $key => $line) {
            $rate = self::rate($line['tax_rate'] ?? null);
            $result[$key] = [
                'taxable' => $taxables[$key],
                'tax' => $rate === null ? Money::of($line['tax'] ?? 0) : '0.00',
                'tax_rate' => $rate,
            ];
            if ($rate !== null) {
                $groups[$rate][$key] = $taxables[$key];
            }
        }

        foreach ($groups as $rate => $members) {
            $groupTax = Money::percentOf(Money::add(...array_values($members)), (string) $rate);
            foreach (Money::allocate($groupTax, $members) as $key => $tax) {
                $result[$key]['tax'] = $tax;
            }
        }

        return $result;
    }

    /**
     * Taxable amount and tax per rate, highest rate first, for the lines
     * taxed by rate. Lines without a rate are left out.
     *
     * @param  array<array-key, array{taxable: string, tax: string, tax_rate: string|null}>  $computed  from compute()
     * @return array<int, array{rate: string, taxable: string, tax: string}>
     */
    public static function summarize(array $computed): array
    {
        $byRate = [];

        foreach ($computed as $line) {
            if ($line['tax_rate'] === null) {
                continue;
            }

            $entry = $byRate[$line['tax_rate']] ?? ['rate' => $line['tax_rate'], 'taxable' => '0.00', 'tax' => '0.00'];
            $entry['taxable'] = Money::add($entry['taxable'], $line['taxable']);
            $entry['tax'] = Money::add($entry['tax'], $line['tax']);
            $byRate[$line['tax_rate']] = $entry;
        }

        uksort($byRate, fn ($a, $b) => bccomp((string) $b, (string) $a, self::RATE_SCALE));

        return array_values($byRate);
    }
}
