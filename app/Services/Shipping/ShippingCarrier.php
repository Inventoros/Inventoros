<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\Shipping\Shipment;

/**
 * A shipping integration: something that can quote, sell, track and void
 * labels for a Shipment. ShipmentService is the only caller; it owns every
 * state change and persistence, so implementations are stateless adapters that
 * translate a Shipment into API calls and API responses into value objects.
 *
 * Implementations throw App\Exceptions\CarrierException, with a readable
 * message, whenever the remote call fails.
 */
interface ShippingCarrier
{
    /**
     * The value stored in shipments.carrier (for example "manual").
     */
    public function key(): string;

    /**
     * Whether this carrier quotes rates and sells labels.
     */
    public function sellsLabels(): bool;

    /**
     * Quote rates for the shipment's parcel, ship-from and ship-to addresses.
     *
     * @return array<int, ShippingRate>
     */
    public function rates(Shipment $shipment): array;

    /**
     * Buy the label for a previously quoted rate.
     */
    public function buyLabel(Shipment $shipment, ShippingRate $rate): PurchasedLabel;

    /**
     * Fetch the latest tracking state, or null when the carrier cannot track
     * this shipment (for example manual entries).
     */
    public function track(Shipment $shipment): ?TrackingUpdate;

    /**
     * Void (refund) the shipment's bought label.
     */
    public function void(Shipment $shipment): void;
}
