<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

use App\Support\ExchangeMath;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What a provider returned: rates for 1 $base, effective on $effectiveDate.
 *
 * The constructor validates everything (currency codes, positive decimal
 * rates in range, a real date not in the future) so a malformed response
 * becomes an ExchangeRateException instead of a stored rate.
 *
 * @api
 */
final class FetchedRates
{
    public readonly string $base;

    public readonly CarbonImmutable $effectiveDate;

    /** @var array<string, string> quote currency => normalized rate */
    public readonly array $rates;

    /**
     * @param  array<string, int|float|string>  $rates
     *
     * @throws ExchangeRateException when any part is malformed
     */
    public function __construct(string $base, CarbonImmutable|string $effectiveDate, array $rates)
    {
        $base = strtoupper(trim($base));
        if (! self::isCurrencyCode($base)) {
            throw ExchangeRateException::invalidResponse("The provider returned an invalid base currency \"{$base}\".");
        }

        if (is_string($effectiveDate)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}\z/', $effectiveDate) !== 1) {
                throw ExchangeRateException::invalidResponse("The provider returned an invalid date \"{$effectiveDate}\".");
            }

            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $effectiveDate);
            } catch (Throwable) {
                $parsed = false;
            }

            if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $effectiveDate) {
                throw ExchangeRateException::invalidResponse("The provider returned an invalid date \"{$effectiveDate}\".");
            }

            $effectiveDate = $parsed;
        }

        // A day of slack for providers in time zones ahead of the server.
        if ($effectiveDate->startOfDay()->greaterThan(CarbonImmutable::now()->addDay()->startOfDay())) {
            throw ExchangeRateException::invalidResponse('The provider returned rates dated in the future.');
        }

        $normalized = [];
        foreach ($rates as $code => $rate) {
            $code = strtoupper(trim((string) $code));

            if (! self::isCurrencyCode($code) || $code === $base) {
                throw ExchangeRateException::invalidResponse("The provider returned an invalid currency \"{$code}\".");
            }

            if (! ExchangeMath::isValidRate($rate)) {
                throw ExchangeRateException::invalidResponse("The provider returned an invalid rate for {$base}/{$code}.");
            }

            $normalized[$code] = ExchangeMath::rate($rate);
        }

        $this->base = $base;
        $this->effectiveDate = $effectiveDate->startOfDay();
        $this->rates = $normalized;
    }

    public static function isCurrencyCode(string $code): bool
    {
        return preg_match('/^[A-Z]{3}\z/', $code) === 1;
    }
}
