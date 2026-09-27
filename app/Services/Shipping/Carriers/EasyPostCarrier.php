<?php

declare(strict_types=1);

namespace App\Services\Shipping\Carriers;

use App\Enums\ShipmentStatus;
use App\Exceptions\CarrierException;
use App\Models\Order\Order;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShippingSetting;
use App\Services\Shipping\PurchasedLabel;
use App\Services\Shipping\ShippingCarrier;
use App\Services\Shipping\ShippingRate;
use App\Services\Shipping\TrackingUpdate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * EasyPost (https://docs.easypost.com), one API in front of USPS, UPS, FedEx,
 * Canada Post, DHL and the rest, called over the Laravel HTTP client.
 *
 *   rates    POST /v2/shipments                   (rates come back on the shipment)
 *   buy      POST /v2/shipments/{id}/buy          {rate: {id}}
 *   track    GET  /v2/trackers/{id}               (or POST /v2/trackers by code)
 *   void     POST /v2/shipments/{id}/refund
 *
 * Authentication is HTTP basic with the API key as the username. Labels are
 * requested as PDF. Parcel weight is in ounces and dimensions in inches, as
 * EasyPost expects.
 */
final class EasyPostCarrier implements ShippingCarrier
{
    public const KEY = 'easypost';

    public const BASE_URL = 'https://api.easypost.com/v2';

    public function __construct(private readonly ShippingSetting $settings) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function sellsLabels(): bool
    {
        return true;
    }

    public function rates(Shipment $shipment): array
    {
        $toAddress = $this->requireAddress($shipment->to_address, 'ship-to');
        $fromAddress = $this->requireAddress($shipment->from_address, 'ship-from');

        if ((float) $shipment->weight_oz <= 0) {
            throw new CarrierException('Enter the parcel weight (in ounces) before fetching rates.');
        }

        $parcel = array_filter([
            'weight' => (float) $shipment->weight_oz,
            'length' => $shipment->length_in !== null ? (float) $shipment->length_in : null,
            'width' => $shipment->width_in !== null ? (float) $shipment->width_in : null,
            'height' => $shipment->height_in !== null ? (float) $shipment->height_in : null,
        ], fn ($v) => $v !== null && $v > 0);

        $order = Order::withoutGlobalScopes()->find($shipment->order_id);

        $body = $this->send('post', '/shipments', [
            'shipment' => [
                'to_address' => $toAddress,
                'from_address' => $fromAddress,
                'parcel' => $parcel,
                'reference' => $order?->order_number,
                'options' => [
                    'label_format' => 'PDF',
                    'currency' => $shipment->currency ?: 'USD',
                ],
            ],
        ]);

        $rates = array_map(fn (array $rate) => new ShippingRate(
            id: (string) $rate['id'],
            carrier: (string) ($rate['carrier'] ?? ''),
            service: (string) ($rate['service'] ?? ''),
            amount: (string) ($rate['rate'] ?? '0'),
            currency: (string) ($rate['currency'] ?? 'USD'),
            deliveryDays: isset($rate['delivery_days']) ? (int) $rate['delivery_days'] : (isset($rate['est_delivery_days']) ? (int) $rate['est_delivery_days'] : null),
            carrierShipmentId: (string) ($rate['shipment_id'] ?? $body['id'] ?? ''),
        ), $body['rates'] ?? []);

        if ($rates === []) {
            $messages = collect($body['messages'] ?? [])
                ->map(fn ($m) => trim(($m['carrier'] ?? '').': '.($m['message'] ?? ''), ': '))
                ->filter()
                ->implode(' ');

            throw new CarrierException(
                'EasyPost returned no rates for this parcel.'.($messages !== '' ? ' '.$messages : ''),
                $body
            );
        }

        usort($rates, fn (ShippingRate $a, ShippingRate $b) => (float) $a->amount <=> (float) $b->amount);

        return $rates;
    }

    public function buyLabel(Shipment $shipment, ShippingRate $rate): PurchasedLabel
    {
        $carrierShipmentId = $rate->carrierShipmentId ?: $shipment->carrier_shipment_id;

        if (blank($carrierShipmentId)) {
            throw new CarrierException('Fetch rates before buying a label.');
        }

        $body = $this->send('post', "/shipments/{$carrierShipmentId}/buy", [
            'rate' => ['id' => $rate->id],
        ]);

        $label = $body['postage_label'] ?? [];
        $selected = $body['selected_rate'] ?? [];
        $tracker = $body['tracker'] ?? [];

        if (blank($body['tracking_code'] ?? null)) {
            throw new CarrierException('EasyPost did not return a tracking number for this label.', $body);
        }

        return new PurchasedLabel(
            trackingNumber: (string) $body['tracking_code'],
            trackingUrl: $tracker['public_url'] ?? null,
            labelUrl: $label['label_pdf_url'] ?? $label['label_url'] ?? null,
            cost: (string) ($selected['rate'] ?? $rate->amount),
            currency: (string) ($selected['currency'] ?? $rate->currency),
            carrierName: (string) ($selected['carrier'] ?? $rate->carrier),
            service: (string) ($selected['service'] ?? $rate->service),
            carrierTrackerId: $tracker['id'] ?? null,
            raw: $body,
        );
    }

    public function track(Shipment $shipment): ?TrackingUpdate
    {
        if (filled($shipment->carrier_tracker_id)) {
            $tracker = $this->send('get', "/trackers/{$shipment->carrier_tracker_id}");
        } elseif (filled($shipment->tracking_number)) {
            // EasyPost de-duplicates trackers by code and carrier, so this is
            // safe to repeat; the returned id is stored for the next poll.
            $tracker = $this->send('post', '/trackers', [
                'tracker' => array_filter([
                    'tracking_code' => $shipment->tracking_number,
                    'carrier' => $shipment->carrier_name,
                ]),
            ]);
        } else {
            return null;
        }

        return self::trackingUpdateFromTracker($tracker);
    }

    public function void(Shipment $shipment): void
    {
        if (blank($shipment->carrier_shipment_id)) {
            return;
        }

        $body = $this->send('post', "/shipments/{$shipment->carrier_shipment_id}/refund");
        $status = $body['refund_status'] ?? null;

        if (! in_array($status, ['submitted', 'refunded'], true)) {
            throw new CarrierException(
                'EasyPost could not void this label'.($status ? " (refund {$status})" : '').'. It may already have been used.',
                $body
            );
        }
    }

    /**
     * Translate an EasyPost Tracker object into a TrackingUpdate.
     *
     * @param  array<string, mixed>  $tracker
     */
    public static function trackingUpdateFromTracker(array $tracker): TrackingUpdate
    {
        $details = $tracker['tracking_details'] ?? [];
        $latest = is_array($details) && $details !== [] ? end($details) : null;

        $occurredAt = null;
        if (is_array($latest) && filled($latest['datetime'] ?? null)) {
            try {
                $occurredAt = Carbon::parse($latest['datetime']);
            } catch (\Throwable) {
                $occurredAt = null;
            }
        }

        return new TrackingUpdate(
            status: self::mapStatus((string) ($tracker['status'] ?? '')),
            detail: is_array($latest) ? ($latest['message'] ?? null) : ($tracker['status_detail'] ?? null),
            trackingUrl: $tracker['public_url'] ?? null,
            carrierTrackerId: $tracker['id'] ?? null,
            occurredAt: $occurredAt,
        );
    }

    /**
     * EasyPost tracker statuses mapped to shipment statuses. pre_transit and
     * unknown map to null: they carry no movement worth recording.
     */
    public static function mapStatus(string $status): ?ShipmentStatus
    {
        return match ($status) {
            'in_transit', 'out_for_delivery', 'available_for_pickup' => ShipmentStatus::IN_TRANSIT,
            'delivered' => ShipmentStatus::DELIVERED,
            'return_to_sender', 'failure', 'error' => ShipmentStatus::EXCEPTION,
            default => null,
        };
    }

    /**
     * Verify an EasyPost webhook signature. EasyPost signs the raw body with
     * HMAC-SHA256 using the webhook secret (NFKD-normalised) and sends
     * "hmac-sha256-hex=<digest>" in the X-Hmac-Signature header.
     */
    public static function verifyWebhookSignature(string $body, ?string $header, ?string $secret): bool
    {
        if (blank($header) || blank($secret)) {
            return false;
        }

        $key = class_exists(\Normalizer::class)
            ? (\Normalizer::normalize((string) $secret, \Normalizer::FORM_KD) ?: (string) $secret)
            : (string) $secret;

        $expected = 'hmac-sha256-hex='.hash_hmac('sha256', $body, $key);

        return hash_equals($expected, trim((string) $header));
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withBasicAuth((string) $this->settings->easyPostKey(), '')
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(30);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws CarrierException
     */
    private function send(string $method, string $path, array $payload = []): array
    {
        try {
            /** @var Response $response */
            $response = $method === 'get'
                ? $this->client()->get($path)
                : $this->client()->post($path, $payload);
        } catch (ConnectionException $e) {
            Log::warning('EasyPost request failed to connect', ['path' => $path, 'error' => $e->getMessage()]);

            throw new CarrierException('Could not reach EasyPost. Check the connection and try again in a moment.', [], $e);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful()) {
            return $body;
        }

        Log::warning('EasyPost request failed', [
            'path' => $path,
            'status' => $response->status(),
            'error' => $body['error'] ?? null,
        ]);

        if (in_array($response->status(), [401, 403], true)) {
            throw new CarrierException(
                'EasyPost rejected the API key. Check the key for the current mode in Settings > Shipping.',
                $body
            );
        }

        $error = $body['error'] ?? [];
        $message = trim((string) ($error['message'] ?? ''));
        $details = collect($error['errors'] ?? [])
            ->map(fn ($e) => is_array($e) ? ($e['message'] ?? null) : (is_string($e) ? $e : null))
            ->filter()
            ->implode(' ');

        $readable = trim(rtrim($message !== '' ? $message : "EasyPost returned HTTP {$response->status()}.", '.').'.'.($details !== '' ? ' '.rtrim($details, '.').'.' : ''));

        throw new CarrierException($readable, $body);
    }

    /**
     * @return array<string, string>
     *
     * @throws CarrierException
     */
    private function requireAddress(?array $address, string $which): array
    {
        $address ??= [];

        foreach (['street1', 'city', 'zip', 'country'] as $required) {
            if (blank($address[$required] ?? null)) {
                throw new CarrierException(
                    "Enter the full {$which} address (street, city, postal code and country) before fetching rates."
                );
            }
        }

        return array_filter($address, fn ($v) => filled($v));
    }
}
