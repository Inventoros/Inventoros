<?php

declare(strict_types=1);

namespace App\Services\Shipping\Carriers;

use App\Exceptions\CarrierException;
use App\Models\Shipping\Shipment;
use App\Services\Shipping\PurchasedLabel;
use App\Services\Shipping\ShippingCarrier;
use App\Services\Shipping\ShippingRate;
use App\Services\Shipping\TrackingUpdate;

/**
 * Tracking entered by hand: the label was bought elsewhere (or the parcel was
 * hand-delivered). Always available, needs no configuration, quotes nothing and
 * tracks nothing; status changes come from the user.
 */
final class ManualCarrier implements ShippingCarrier
{
    public const KEY = 'manual';

    public function key(): string
    {
        return self::KEY;
    }

    public function sellsLabels(): bool
    {
        return false;
    }

    public function rates(Shipment $shipment): array
    {
        return [];
    }

    public function buyLabel(Shipment $shipment, ShippingRate $rate): PurchasedLabel
    {
        throw new CarrierException('Manual shipments do not buy labels. Enter the tracking number instead.');
    }

    public function track(Shipment $shipment): ?TrackingUpdate
    {
        return null;
    }

    public function void(Shipment $shipment): void
    {
        // Nothing was bought, so there is nothing to refund.
    }
}
