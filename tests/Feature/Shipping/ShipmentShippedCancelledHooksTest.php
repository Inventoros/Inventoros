<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\ShipmentStatus;
use App\Models\Shipping\Shipment;
use App\Services\OrderService;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * shipment_shipped fires once, after commit, when a shipment first leaves the
 * warehouse, whichever way it left: marked shipped (web, REST, MCP), or a
 * carrier tracking update that jumps straight to in transit or delivered.
 * shipment_cancelled fires once, after commit, when a shipment is cancelled
 * on its own or closed by cancelling its order.
 */
final class ShipmentShippedCancelledHooksTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    /** @var array<string, array<int, array<int, mixed>>> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpShipping();

        foreach (['shipment_shipped', 'shipment_cancelled', 'shipment_delivered'] as $hook) {
            add_action($hook, function (...$args) use ($hook) {
                $this->fired[$hook][] = $args;
            });
        }
    }

    private function timesFired(string $hook): int
    {
        return count($this->fired[$hook] ?? []);
    }

    public function test_marking_a_shipment_shipped_fires_once(): void
    {
        $service = app(ShipmentService::class);
        $shipment = $service->create($this->makeOrder(), ['carrier' => 'manual', 'tracking_number' => 'S-1'], $this->admin);

        $service->markShipped($shipment, $this->admin);
        $service->markShipped($shipment->fresh(), $this->admin);
        $service->applyTrackingStatus($shipment->fresh(), ShipmentStatus::IN_TRANSIT);

        $this->assertSame(1, $this->timesFired('shipment_shipped'));
        $fired = $this->fired['shipment_shipped'][0][0];
        $this->assertInstanceOf(Shipment::class, $fired);
        $this->assertSame($shipment->id, $fired->id);
        $this->assertSame(ShipmentStatus::SHIPPED, $fired->status);
        $this->assertSame(0, $this->timesFired('shipment_cancelled'));
    }

    public function test_a_tracking_update_straight_to_delivered_fires_shipped_then_delivered(): void
    {
        $service = app(ShipmentService::class);
        $shipment = $service->create($this->makeOrder(), ['carrier' => 'manual'], $this->admin);

        $service->applyTrackingStatus($shipment, ShipmentStatus::DELIVERED);

        $this->assertSame(1, $this->timesFired('shipment_shipped'));
        $this->assertSame(1, $this->timesFired('shipment_delivered'));
    }

    public function test_the_rest_api_fires_shipped(): void
    {
        $order = $this->makeOrder();
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/orders/{$order->id}/shipments", ['carrier_name' => 'FedEx', 'mark_shipped' => true])
            ->assertCreated();

        $this->assertSame(1, $this->timesFired('shipment_shipped'));
    }

    public function test_shipped_waits_for_the_surrounding_transaction_and_skips_a_rollback(): void
    {
        $service = app(ShipmentService::class);
        $shipment = $service->create($this->makeOrder(), ['carrier' => 'manual'], $this->admin);

        try {
            DB::transaction(function () use ($service, $shipment) {
                $service->markShipped($shipment, $this->admin);
                $this->assertSame(0, $this->timesFired('shipment_shipped'), 'fired before commit');

                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, $this->timesFired('shipment_shipped'));
        $this->assertSame(ShipmentStatus::PENDING, $shipment->fresh()->status);
    }

    public function test_cancelling_a_shipment_fires_cancelled_once(): void
    {
        $service = app(ShipmentService::class);
        $shipment = $service->create($this->makeOrder(), ['carrier' => 'manual'], $this->admin);

        $service->cancel($shipment);

        $this->assertSame(1, $this->timesFired('shipment_cancelled'));
        $this->assertSame($shipment->id, $this->fired['shipment_cancelled'][0][0]->id);
        $this->assertSame(ShipmentStatus::CANCELLED, $this->fired['shipment_cancelled'][0][0]->status);
        $this->assertSame(0, $this->timesFired('shipment_shipped'));
    }

    public function test_cancelling_the_order_fires_cancelled_for_each_open_shipment(): void
    {
        $order = $this->makeOrder();
        $line = $order->items->first();
        $service = app(ShipmentService::class);
        $service->create($order, ['carrier' => 'manual', 'items' => [['order_item_id' => $line->id, 'quantity' => 1]]], $this->admin);
        $service->create($order, ['carrier' => 'manual', 'items' => [['order_item_id' => $line->id, 'quantity' => 1]]], $this->admin);

        app(OrderService::class)->cancel($order->fresh(), $this->admin);

        $this->assertSame(2, $this->timesFired('shipment_cancelled'));
    }
}
