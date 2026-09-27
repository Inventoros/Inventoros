<?php

declare(strict_types=1);

namespace App\Services\Shipping;

/**
 * One quoted rate. carrierShipmentId is the carrier-side shipment the rate
 * belongs to (EasyPost quotes rates on a shipment object and buys against it).
 */
final class ShippingRate
{
    public function __construct(
        public readonly string $id,
        public readonly string $carrier,
        public readonly string $service,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?int $deliveryDays = null,
        public readonly ?string $carrierShipmentId = null,
    ) {}

    /**
     * @return array{id: string, carrier: string, service: string, amount: string, currency: string, delivery_days: int|null, carrier_shipment_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'carrier' => $this->carrier,
            'service' => $this->service,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'delivery_days' => $this->deliveryDays,
            'carrier_shipment_id' => $this->carrierShipmentId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            carrier: (string) ($data['carrier'] ?? ''),
            service: (string) ($data['service'] ?? ''),
            amount: (string) ($data['amount'] ?? '0'),
            currency: (string) ($data['currency'] ?? 'USD'),
            deliveryDays: isset($data['delivery_days']) ? (int) $data['delivery_days'] : null,
            carrierShipmentId: $data['carrier_shipment_id'] ?? null,
        );
    }
}
