<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

/**
 * What an exchange rate provider can do.
 *
 * - automatic: core may call fetch() (on demand and on the sync schedule).
 *   False for a provider whose rates are only ever entered by hand.
 * - historical: fetch() accepts a past date.
 * - shareable: the data is public and identical for every customer, so core
 *   stores one copy for all organizations and asks once for all of them.
 *   A provider whose data is licensed per customer (a paid API key) must
 *   declare false; its rates are then stored per organization and never
 *   shown to, or reused for, another one.
 *
 * @api
 */
final class ProviderCapabilities
{
    public function __construct(
        public readonly bool $automatic = true,
        public readonly bool $historical = false,
        public readonly bool $shareable = false,
    ) {}

    /**
     * @return array{automatic: bool, historical: bool, shareable: bool}
     */
    public function toArray(): array
    {
        return [
            'automatic' => $this->automatic,
            'historical' => $this->historical,
            'shareable' => $this->shareable,
        ];
    }
}
