<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Exceptions\ShippingException;
use App\Models\Shipping\ShippingSetting;
use App\Services\Shipping\Carriers\EasyPostCarrier;
use App\Services\Shipping\Carriers\ManualCarrier;

/**
 * Resolves the ShippingCarrier for an organization. Manual is always
 * available; EasyPost only once it is enabled with an API key in
 * Settings > Shipping.
 */
final class CarrierManager
{
    /**
     * @throws ShippingException When the carrier is unknown or not configured.
     */
    public function for(string $key, int $organizationId): ShippingCarrier
    {
        return match ($key) {
            ManualCarrier::KEY => new ManualCarrier,
            EasyPostCarrier::KEY => $this->easyPost($organizationId),
            default => throw new ShippingException("Unknown shipping carrier \"{$key}\"."),
        };
    }

    /**
     * The carrier keys this organization can create shipments with.
     *
     * @return array<int, string>
     */
    public function available(int $organizationId): array
    {
        $keys = [ManualCarrier::KEY];

        if (ShippingSetting::forOrganization($organizationId)->easyPostConfigured()) {
            $keys[] = EasyPostCarrier::KEY;
        }

        return $keys;
    }

    private function easyPost(int $organizationId): EasyPostCarrier
    {
        $settings = ShippingSetting::forOrganization($organizationId);

        if (! $settings->easyPostConfigured()) {
            throw new ShippingException('EasyPost is not set up. Add an API key in Settings > Shipping.');
        }

        return new EasyPostCarrier($settings);
    }
}
