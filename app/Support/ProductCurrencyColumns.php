<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;

/**
 * The per-currency price columns of the product CSV (price_EUR, price_GBP, ...).
 *
 * A product's additional prices live in the price_in_currencies JSON map. The
 * spreadsheet flattens that map into one column per currency so it can be
 * edited like any other price. The columns offered are the currencies the
 * organization actually uses: those listed in its supported_currencies, plus
 * any currency one of its products already carries a price in (so an export
 * never drops data). Only codes from config('currencies.supported') count.
 */
final class ProductCurrencyColumns
{
    /**
     * Import heading keys look like price_eur (the heading row is slugged).
     */
    private const IMPORT_KEY_PATTERN = '/^price_([a-z]{3})$/';

    /**
     * The currency codes the organization uses, in config order.
     *
     * @return array<int, string>
     */
    public static function currenciesFor(int $organizationId): array
    {
        $used = [];

        $configured = Organization::whereKey($organizationId)->value('supported_currencies');
        if (is_string($configured)) {
            $configured = json_decode($configured, true);
        }
        foreach ((array) $configured as $code) {
            if (is_string($code)) {
                $used[strtoupper($code)] = true;
            }
        }

        Product::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull('price_in_currencies')
            ->select(['id', 'price_in_currencies'])
            ->chunkById(1000, function ($products) use (&$used) {
                foreach ($products as $product) {
                    foreach (array_keys((array) $product->price_in_currencies) as $code) {
                        $used[strtoupper((string) $code)] = true;
                    }
                }
            });

        return array_values(array_filter(
            array_keys(config('currencies.supported', [])),
            fn (string $code) => isset($used[$code]),
        ));
    }

    /**
     * The currency code an import heading key refers to, or null when the key
     * is not a per-currency price column at all.
     */
    public static function currencyFromImportKey(string $key): ?string
    {
        return preg_match(self::IMPORT_KEY_PATTERN, $key, $m) === 1 ? strtoupper($m[1]) : null;
    }

    /**
     * Whether a code is one of the application's supported currencies.
     */
    public static function isSupported(string $code): bool
    {
        return array_key_exists($code, config('currencies.supported', []));
    }
}
