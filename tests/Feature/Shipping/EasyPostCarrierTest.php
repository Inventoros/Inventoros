<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\CarrierException;
use App\Exceptions\ShippingException;
use App\Models\Shipping\Shipment;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * EasyPost over the Laravel HTTP client. Every call is faked with fixtures
 * shaped like EasyPost's documented responses; stray requests fail the test,
 * so nothing ever reaches the real API.
 */
class EasyPostCarrierTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    private const LABEL_URL = 'https://easypost-files.s3.us-west-2.amazonaws.com/files/postage_label/20260927/e8a8d3b1c5f24a9e8f1b.pdf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function service(): ShipmentService
    {
        return app(ShipmentService::class);
    }

    private function pendingEasyPostShipment(): Shipment
    {
        $this->configureEasyPost();

        return $this->service()->create($this->makeRateableOrder(), ['carrier' => 'easypost'], $this->admin);
    }

    private function ratedShipment(): Shipment
    {
        $shipment = $this->pendingEasyPostShipment();
        Http::fake(['api.easypost.com/v2/shipments' => Http::response($this->easyPostFixture('shipment_with_rates'), 201)]);
        $this->service()->fetchRates($shipment);

        return $shipment->fresh();
    }

    public function test_rates_are_quoted_with_the_test_key_and_remembered(): void
    {
        $shipment = $this->pendingEasyPostShipment();
        Http::fake(['api.easypost.com/v2/shipments' => Http::response($this->easyPostFixture('shipment_with_rates'), 201)]);

        $rates = $this->service()->fetchRates($shipment);

        $this->assertCount(2, $rates);
        $this->assertSame('USPS', $rates[0]->carrier);
        $this->assertSame('Priority', $rates[0]->service);
        $this->assertSame('7.58', $rates[0]->amount);
        $this->assertSame(2, $rates[0]->deliveryDays);

        $shipment->refresh();
        $this->assertSame('shp_4d7f2c9b1e5a4f0e9c3b8a2d6e1f0a7b', $shipment->carrier_shipment_id);
        $this->assertCount(2, $shipment->carrier_rates);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.easypost.com/v2/shipments'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('EZTK_test_key_123:'))
                && $body['shipment']['to_address']['street1'] === '388 Townsend St'
                && $body['shipment']['to_address']['zip'] === '94107'
                && $body['shipment']['from_address']['street1'] === '1 Dock St'
                && (float) $body['shipment']['parcel']['weight'] === 15.4
                && $body['shipment']['options']['label_format'] === 'PDF';
        });
    }

    public function test_production_mode_uses_the_production_key(): void
    {
        $this->configureEasyPost(['easypost_test_mode' => false]);
        $shipment = $this->service()->create($this->makeRateableOrder(), ['carrier' => 'easypost'], $this->admin);
        Http::fake(['api.easypost.com/v2/shipments' => Http::response($this->easyPostFixture('shipment_with_rates'), 201)]);

        $this->service()->fetchRates($shipment);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('EZAK_live_key_456:')));
    }

    public function test_no_rates_surfaces_the_carrier_messages(): void
    {
        $shipment = $this->pendingEasyPostShipment();
        Http::fake(['api.easypost.com/v2/shipments' => Http::response($this->easyPostFixture('shipment_no_rates'), 201)]);

        $this->expectException(CarrierException::class);
        $this->expectExceptionMessage('weight exceeds 70 lbs');

        $this->service()->fetchRates($shipment);
    }

    public function test_an_address_error_becomes_a_readable_message(): void
    {
        $shipment = $this->pendingEasyPostShipment();
        Http::fake(['api.easypost.com/v2/shipments' => Http::response($this->easyPostFixture('error_address'), 422)]);

        try {
            $this->service()->fetchRates($shipment);
            $this->fail('Expected a carrier error.');
        } catch (CarrierException $e) {
            $this->assertStringContainsString('Unable to verify address', $e->getMessage());
            $this->assertStringContainsString('Address not found', $e->getMessage());
        }

        $this->assertNull($shipment->fresh()->carrier_shipment_id);
    }

    public function test_a_rejected_api_key_points_at_settings(): void
    {
        $shipment = $this->pendingEasyPostShipment();
        Http::fake(['api.easypost.com/v2/shipments' => Http::response($this->easyPostFixture('error_unauthorized'), 401)]);

        $this->expectException(CarrierException::class);
        $this->expectExceptionMessage('Settings > Shipping');

        $this->service()->fetchRates($shipment);
    }

    public function test_a_network_failure_is_reported_without_a_stack_trace(): void
    {
        $shipment = $this->pendingEasyPostShipment();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->expectException(CarrierException::class);
        $this->expectExceptionMessage('Could not reach EasyPost');

        $this->service()->fetchRates($shipment);
    }

    public function test_an_incomplete_ship_to_address_is_rejected_before_calling_easypost(): void
    {
        $this->configureEasyPost();
        $shipment = $this->service()->create($this->makeOrder(), ['carrier' => 'easypost'], $this->admin);

        try {
            $this->service()->fetchRates($shipment);
            $this->fail('Expected a carrier error.');
        } catch (CarrierException $e) {
            $this->assertStringContainsString('ship-to address', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_buying_a_label_saves_tracking_and_the_label_on_the_private_disk(): void
    {
        $shipment = $this->ratedShipment();
        Http::fake([
            'api.easypost.com/v2/shipments/*/buy' => Http::response($this->easyPostFixture('shipment_bought')),
            'easypost-files.s3.us-west-2.amazonaws.com/*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $bought = $this->service()->buyLabel($shipment, 'rate_3f1b2c4d5e6f4a7b8c9d0e1f2a3b4c5d');

        $this->assertSame(ShipmentStatus::LABEL_CREATED, $bought->status);
        $this->assertSame('9400100208271109836487', $bought->tracking_number);
        $this->assertSame('USPS', $bought->carrier_name);
        $this->assertSame('Priority', $bought->service);
        $this->assertSame('7.58', (string) $bought->cost);
        $this->assertSame('trk_c1d2e3f4a5b64c7d8e9f0a1b2c3d4e5f', $bought->carrier_tracker_id);
        $this->assertStringStartsWith('https://track.easypost.com/', $bought->tracking_url);
        $this->assertSame(self::LABEL_URL, $bought->label_url);
        $this->assertSame('pl_2b8c1d4e7f0a4b3c9d6e5f1a2b3c4d5e', $bought->carrier_response['postage_label']['id']);
        $this->assertNotNull($bought->label_path);
        Storage::disk('local')->assertExists($bought->label_path);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.easypost.com/v2/shipments/shp_4d7f2c9b1e5a4f0e9c3b8a2d6e1f0a7b/buy'
            && $r['rate']['id'] === 'rate_3f1b2c4d5e6f4a7b8c9d0e1f2a3b4c5d');
    }

    public function test_a_failed_label_download_keeps_the_bought_label_and_retries_later(): void
    {
        $shipment = $this->ratedShipment();
        Http::fake([
            'api.easypost.com/v2/shipments/*/buy' => Http::response($this->easyPostFixture('shipment_bought')),
            'easypost-files.s3.us-west-2.amazonaws.com/*' => Http::sequence()
                ->push('Service Unavailable', 503)
                ->push('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $bought = $this->service()->buyLabel($shipment, 'rate_3f1b2c4d5e6f4a7b8c9d0e1f2a3b4c5d');

        $this->assertSame(ShipmentStatus::LABEL_CREATED, $bought->status);
        $this->assertSame('9400100208271109836487', $bought->tracking_number);
        $this->assertSame(self::LABEL_URL, $bought->label_url);
        $this->assertNull($bought->label_path);

        $this->assertSame('%PDF-1.4 label', $this->service()->labelContents($bought));
        $this->assertNotNull($bought->fresh()->label_path);
    }

    public function test_an_unknown_rate_is_refused_without_calling_easypost(): void
    {
        $shipment = $this->ratedShipment();
        Http::fake();

        $this->expectException(ShippingException::class);
        $this->service()->buyLabel($shipment, 'rate_not_offered');
    }

    public function test_a_failed_purchase_leaves_the_shipment_pending(): void
    {
        $shipment = $this->ratedShipment();
        Http::fake(['api.easypost.com/v2/shipments/*/buy' => Http::response($this->easyPostFixture('error_rate_expired'), 422)]);

        try {
            $this->service()->buyLabel($shipment, 'rate_3f1b2c4d5e6f4a7b8c9d0e1f2a3b4c5d');
            $this->fail('Expected a carrier error.');
        } catch (CarrierException $e) {
            $this->assertStringContainsString('no longer available', $e->getMessage());
        }

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::PENDING, $shipment->status);
        $this->assertNull($shipment->tracking_number);
        $this->assertNull($shipment->label_url);
    }

    public function test_voiding_a_label_requests_a_refund_and_cancels_the_shipment(): void
    {
        $shipment = $this->boughtShipment();
        Http::fake(['api.easypost.com/v2/shipments/*/refund' => Http::response($this->easyPostFixture('shipment_refund_submitted'))]);

        $this->service()->cancel($shipment);

        $this->assertSame(ShipmentStatus::CANCELLED, $shipment->fresh()->status);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.easypost.com/v2/shipments/shp_4d7f2c9b1e5a4f0e9c3b8a2d6e1f0a7b/refund');
    }

    public function test_a_rejected_refund_leaves_the_label_in_place(): void
    {
        $shipment = $this->boughtShipment();
        Http::fake(['api.easypost.com/v2/shipments/*/refund' => Http::response($this->easyPostFixture('shipment_refund_rejected'))]);

        $this->expectException(CarrierException::class);

        try {
            $this->service()->cancel($shipment);
        } finally {
            $this->assertSame(ShipmentStatus::LABEL_CREATED, $shipment->fresh()->status);
        }
    }

    public function test_tracking_poll_moves_the_shipment_and_order_along(): void
    {
        $shipment = $this->boughtShipment();
        Http::fake(['api.easypost.com/v2/trackers/*' => Http::response($this->easyPostFixture('tracker_in_transit'))]);

        $this->service()->refreshTracking($shipment);

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::IN_TRANSIT, $shipment->status);
        $this->assertSame('Arrived at USPS Regional Facility', $shipment->tracking_status_detail);
        $this->assertNotNull($shipment->shipped_at);
        $this->assertNotNull($shipment->last_tracked_at);
        $this->assertSame(OrderStatus::SHIPPED, $shipment->order->status);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://api.easypost.com/v2/trackers/trk_c1d2e3f4a5b64c7d8e9f0a1b2c3d4e5f');
    }

    public function test_easypost_cannot_be_used_until_configured(): void
    {
        $this->expectException(ShippingException::class);
        $this->expectExceptionMessage('Settings > Shipping');

        $this->service()->create($this->makeRateableOrder(), ['carrier' => 'easypost'], $this->admin);
    }

    public function test_api_keys_are_encrypted_at_rest(): void
    {
        $this->configureEasyPost();

        $raw = \DB::table('shipping_settings')->where('organization_id', $this->organization->id)->first();

        $this->assertNotSame('EZTK_test_key_123', $raw->easypost_test_api_key);
        $this->assertStringNotContainsString('EZTK_test_key_123', (string) $raw->easypost_test_api_key);
        $this->assertStringNotContainsString('whsec_example_secret', (string) $raw->easypost_webhook_secret);
    }

    private function boughtShipment(): Shipment
    {
        $shipment = $this->ratedShipment();
        Http::fake([
            'api.easypost.com/v2/shipments/*/buy' => Http::response($this->easyPostFixture('shipment_bought')),
            'easypost-files.s3.us-west-2.amazonaws.com/*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $bought = $this->service()->buyLabel($shipment, 'rate_3f1b2c4d5e6f4a7b8c9d0e1f2a3b4c5d');

        // Later fakes in the same test must not match these stubs first.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();

        return $bought;
    }
}
