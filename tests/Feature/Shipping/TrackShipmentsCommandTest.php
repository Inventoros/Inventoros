<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\ShipmentStatus;
use App\Models\Shipping\Shipment;
use App\Services\Shipping\ShipmentService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TrackShipmentsCommandTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
        $this->configureEasyPost();
        Http::preventStrayRequests();
        RateLimiter::clear('shipping-track:'.$this->organization->id);
    }

    /**
     * A shipped EasyPost shipment with its own order and tracker id.
     */
    private function shipped(string $trackerId, array $attributes = []): Shipment
    {
        $service = app(ShipmentService::class);
        $shipment = $service->create($this->makeOrder([[$this->widget, 1]]), ['carrier' => 'manual', 'tracking_number' => 'T-'.$trackerId], $this->admin);
        $shipment = $service->markShipped($shipment, $this->admin);
        $shipment->forceFill(array_merge(['carrier' => 'easypost', 'carrier_tracker_id' => $trackerId, 'last_tracked_at' => null], $attributes))->save();

        return $shipment;
    }

    public function test_it_polls_shipments_on_their_way_and_applies_the_status(): void
    {
        $shipment = $this->shipped('trk_c1d2e3f4a5b64c7d8e9f0a1b2c3d4e5f');
        Http::fake(['api.easypost.com/v2/trackers/*' => Http::response($this->easyPostFixture('tracker_in_transit'))]);

        $this->artisan('shipping:track')->assertSuccessful();

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::IN_TRANSIT, $shipment->status);
        $this->assertNotNull($shipment->last_tracked_at);
    }

    public function test_manual_delivered_and_recently_polled_shipments_are_skipped(): void
    {
        $manual = $this->shipped('trk_manual', ['carrier' => 'manual']);
        $delivered = $this->shipped('trk_delivered', ['status' => ShipmentStatus::DELIVERED]);
        $recent = $this->shipped('trk_recent', ['last_tracked_at' => now()->subMinutes(5)]);
        Http::fake();

        $this->artisan('shipping:track')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(ShipmentStatus::SHIPPED, $manual->fresh()->status);
        $this->assertSame(ShipmentStatus::DELIVERED, $delivered->fresh()->status);
        $this->assertSame(ShipmentStatus::SHIPPED, $recent->fresh()->status);
    }

    public function test_calls_are_rate_limited_per_organization(): void
    {
        $this->shipped('trk_one');
        $this->shipped('trk_two');
        $this->shipped('trk_three');
        Http::fake(['api.easypost.com/v2/trackers/*' => Http::response($this->easyPostFixture('tracker_in_transit'))]);

        $this->artisan('shipping:track', ['--per-minute' => 2])->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertSame(1, Shipment::whereNull('last_tracked_at')->count());
    }

    public function test_a_carrier_error_on_one_shipment_does_not_stop_the_rest(): void
    {
        $broken = $this->shipped('trk_broken');
        $fine = $this->shipped('trk_fine');
        Http::fake([
            'api.easypost.com/v2/trackers/trk_broken' => Http::response($this->easyPostFixture('error_unauthorized'), 401),
            'api.easypost.com/v2/trackers/trk_fine' => Http::response($this->easyPostFixture('tracker_in_transit')),
        ]);

        $this->artisan('shipping:track')->assertSuccessful();

        $this->assertSame(ShipmentStatus::SHIPPED, $broken->fresh()->status);
        $this->assertNotNull($broken->fresh()->last_tracked_at);
        $this->assertSame(ShipmentStatus::IN_TRANSIT, $fine->fresh()->status);
    }

    public function test_the_poll_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'shipping:track'));

        $this->assertCount(1, $events);
    }
}
