<?php

declare(strict_types=1);

namespace App\Services\Shipping;

/**
 * What a carrier returns when a label is bought. $raw is the full response,
 * which ShipmentService stores before doing anything else so a paid label can
 * always be recovered.
 */
final class PurchasedLabel
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $trackingNumber,
        public readonly ?string $trackingUrl,
        public readonly ?string $labelUrl,
        public readonly string $cost,
        public readonly string $currency,
        public readonly string $carrierName,
        public readonly string $service,
        public readonly ?string $carrierTrackerId,
        public readonly array $raw,
    ) {}
}
