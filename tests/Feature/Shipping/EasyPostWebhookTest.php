<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShippingSetting;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST /webhooks/easypost/{token}: EasyPost tracker events, verified with the
 * organization's webhook secret (X-Hmac-Signature: hmac-sha256-hex=...).
 */
class EasyPostWebhookTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    private ShippingSetting $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
        Http::preventStrayRequests();
        $this->settings = $this->configureEasyPost();
    }

    private function shippedShipment(): Shipment
    {
        $service = app(ShipmentService::class);
        $shipment = $service->create($this->makeOrder(), ['carrier' => 'manual', 'tracking_number' => '9400100208271109836487'], $this->admin);
        $shipment->forceFill(['carrier' => 'easypost', 'carrier_tracker_id' => 'trk_c1d2e3f4a5b64c7d8e9f0a1b2c3d4e5f'])->save();

        return $service->markShipped($shipment, $this->admin);
    }

    private function sendEvent(string $body, ?string $signature = null, ?string $token = null)
    {
        $signature ??= 'hmac-sha256-hex='.hash_hmac('sha256', $body, 'whsec_example_secret');

        return $this->call(
            'POST',
            '/webhooks/easypost/'.($token ?? $this->settings->webhook_token),
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HMAC_SIGNATURE' => $signature],
            $body
        );
    }

    private function deliveredEvent(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/easypost/event_tracker_delivered.json'));
    }

    public function test_a_signed_delivery_event_delivers_the_shipment_and_the_order(): void
    {
        $shipment = $this->shippedShipment();

        $this->sendEvent($this->deliveredEvent())->assertOk()->assertJson(['updated' => 1]);

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::DELIVERED, $shipment->status);
        $this->assertSame('Delivered, Front Door/Porch', $shipment->tracking_status_detail);
        $this->assertSame('2026-09-30 18:04:00', $shipment->delivered_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(OrderStatus::DELIVERED, $shipment->order->status);
    }

    public function test_the_tracking_number_matches_when_no_tracker_id_is_stored(): void
    {
        $shipment = $this->shippedShipment();
        $shipment->forceFill(['carrier_tracker_id' => null])->save();

        $this->sendEvent($this->deliveredEvent())->assertOk();

        $this->assertSame(ShipmentStatus::DELIVERED, $shipment->fresh()->status);
        $this->assertSame('trk_c1d2e3f4a5b64c7d8e9f0a1b2c3d4e5f', $shipment->fresh()->carrier_tracker_id);
    }

    public function test_a_bad_signature_is_rejected_and_changes_nothing(): void
    {
        $shipment = $this->shippedShipment();

        $this->sendEvent($this->deliveredEvent(), 'hmac-sha256-hex='.str_repeat('0', 64))->assertStatus(401);
        $this->sendEvent($this->deliveredEvent(), '')->assertStatus(401);

        $this->assertSame(ShipmentStatus::SHIPPED, $shipment->fresh()->status);
    }

    public function test_a_tampered_body_fails_verification(): void
    {
        $shipment = $this->shippedShipment();
        $body = $this->deliveredEvent();
        $signature = 'hmac-sha256-hex='.hash_hmac('sha256', $body, 'whsec_example_secret');

        $this->sendEvent(str_replace('"delivered"', '"in_transit"', $body), $signature)->assertStatus(401);

        $this->assertSame(ShipmentStatus::SHIPPED, $shipment->fresh()->status);
    }

    public function test_without_a_configured_secret_every_event_is_rejected(): void
    {
        $this->shippedShipment();
        $this->settings->forceFill(['easypost_webhook_secret' => null])->save();

        $this->sendEvent($this->deliveredEvent())->assertStatus(401);
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->sendEvent($this->deliveredEvent(), null, str_repeat('x', 48))->assertNotFound();
    }

    public function test_events_for_unknown_trackers_and_other_objects_are_acknowledged(): void
    {
        $this->sendEvent($this->deliveredEvent())->assertOk()->assertJson(['updated' => 0]);

        $batch = json_encode(['object' => 'Event', 'description' => 'batch.updated', 'result' => ['object' => 'Batch', 'id' => 'batch_1']]);
        $this->sendEvent($batch)->assertOk()->assertJson(['updated' => 0]);
    }

    public function test_another_organizations_shipment_with_the_same_code_is_untouched(): void
    {
        $shipment = $this->shippedShipment();
        $other = \App\Models\Auth\Organization::create(['name' => 'Other', 'email' => 'o@o.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $otherSettings = ShippingSetting::forOrganization($other->id);
        $otherSettings->forceFill(['easypost_webhook_secret' => 'whsec_example_secret'])->save();

        $this->sendEvent($this->deliveredEvent(), null, $otherSettings->webhook_token)->assertOk()->assertJson(['updated' => 0]);

        $this->assertSame(ShipmentStatus::SHIPPED, $shipment->fresh()->status);
    }
}
