<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipping\Shipment;
use App\Services\Shipping\ShipmentService;
use App\Services\Shipping\TrackingUpdate;
use App\Support\PublicHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Label and tracking URLs come from the carrier (or a carrier plugin). The
 * app server downloads the label, so that fetch must only reach public hosts,
 * must not follow redirects and must be size-bounded; and only http(s) links
 * are kept for the order and customer pages.
 */
class ShippingLabelUrlGuardTest extends TestCase
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

    private function ratedShipment(): Shipment
    {
        $this->configureEasyPost();
        $shipment = $this->service()->create($this->makeRateableOrder(), ['carrier' => 'easypost'], $this->admin);
        Http::fake(['api.easypost.com/v2/shipments' => Http::response($this->easyPostFixture('shipment_with_rates'), 201)]);
        $this->service()->fetchRates($shipment);

        return $shipment->fresh();
    }

    /** @param array<string, mixed> $postageLabel */
    private function boughtFixture(array $postageLabel = [], ?string $trackingUrl = null): array
    {
        $bought = $this->easyPostFixture('shipment_bought');
        $bought['postage_label'] = array_merge($bought['postage_label'], $postageLabel);
        if ($trackingUrl !== null) {
            $bought['tracker']['public_url'] = $trackingUrl;
        }

        return $bought;
    }

    private function buy(Shipment $shipment): Shipment
    {
        return $this->service()->buyLabel($shipment, 'rate_3f1b2c4d5e6f4a7b8c9d0e1f2a3b4c5d');
    }

    public function test_a_label_url_resolving_to_a_private_address_is_never_fetched(): void
    {
        $shipment = $this->ratedShipment();
        $this->mock(PublicHostResolver::class)->shouldReceive('resolve')->andReturn(['169.254.169.254']);
        Http::fake([
            'api.easypost.com/v2/shipments/*/buy' => Http::response($this->boughtFixture()),
            'easypost-files.s3.us-west-2.amazonaws.com/*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $bought = $this->buy($shipment);

        $this->assertNull($bought->label_path);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'easypost-files'));
    }

    public function test_a_label_download_does_not_follow_redirects(): void
    {
        $shipment = $this->ratedShipment();
        Http::fake([
            'api.easypost.com/v2/shipments/*/buy' => Http::response($this->boughtFixture()),
            'easypost-files.s3.us-west-2.amazonaws.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '169.254.169.254/*' => Http::response('metadata', 200),
        ]);

        $bought = $this->buy($shipment);

        $this->assertNull($bought->label_path);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '169.254.169.254'));
    }

    public function test_an_oversized_label_is_not_stored(): void
    {
        $shipment = $this->ratedShipment();
        Http::fake([
            'api.easypost.com/v2/shipments/*/buy' => Http::response($this->boughtFixture()),
            'easypost-files.s3.us-west-2.amazonaws.com/*' => Http::response(str_repeat('x', ShipmentService::LABEL_MAX_BYTES + 1), 200, ['Content-Type' => 'application/pdf']),
        ]);

        $bought = $this->buy($shipment);

        $this->assertNull($bought->label_path);
        $this->assertSame([], Storage::disk('local')->allFiles('shipping-labels'));
    }

    public function test_a_public_label_is_still_downloaded_and_stored(): void
    {
        $shipment = $this->ratedShipment();
        Http::fake([
            'api.easypost.com/v2/shipments/*/buy' => Http::response($this->boughtFixture()),
            'easypost-files.s3.us-west-2.amazonaws.com/*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $bought = $this->buy($shipment);

        $this->assertSame(self::LABEL_URL, $bought->label_url);
        $this->assertNotNull($bought->label_path);
        $this->assertSame('%PDF-1.4 label', Storage::disk('local')->get($bought->label_path));
    }

    public function test_non_http_carrier_urls_are_not_stored(): void
    {
        $shipment = $this->ratedShipment();
        Http::fake([
            'api.easypost.com/v2/shipments/*/buy' => Http::response($this->boughtFixture(
                ['label_pdf_url' => 'file:///etc/passwd', 'label_url' => 'file:///etc/passwd'],
                'javascript:alert(document.cookie)',
            )),
        ]);

        $bought = $this->buy($shipment);

        $this->assertNull($bought->label_url);
        $this->assertNull($bought->label_path);
        $this->assertStringStartsWith('https://', (string) $bought->tracking_url);
    }

    public function test_a_tracking_update_with_a_non_http_url_is_ignored(): void
    {
        $shipment = $this->service()->create($this->makeOrder(), ['carrier' => 'manual'], $this->admin);
        $shipment->forceFill(['tracking_url' => null])->save();

        $this->service()->applyTrackingUpdate($shipment, new TrackingUpdate(null, trackingUrl: 'javascript:alert(1)'));
        $this->assertNull($shipment->fresh()->tracking_url);

        $this->service()->applyTrackingUpdate($shipment->fresh(), new TrackingUpdate(null, trackingUrl: 'https://tracking.example.com/abc'));
        $this->assertSame('https://tracking.example.com/abc', $shipment->fresh()->tracking_url);
    }
}
