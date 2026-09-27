<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Jobs\WebhookDeliveryJob;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreateShipmentTool;
use App\Mcp\Tools\ListShipmentsTool;
use App\Models\Shipping\Shipment;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Shipping\ShipmentService;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShipmentApiMcpAndEventsTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
    }

    // ---- REST ---------------------------------------------------------

    public function test_rest_creates_a_manual_shipment_and_marks_it_shipped(): void
    {
        $order = $this->makeOrder();
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/orders/{$order->id}/shipments", [
            'carrier_name' => 'FedEx',
            'tracking_number' => '794612345678',
            'mark_shipped' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.tracking_url', 'https://www.fedex.com/fedextrack/?trknbr=794612345678')
            ->assertJsonMissingPath('data.label_path');

        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_rest_lists_shipments_for_an_order_and_overall(): void
    {
        $order = $this->makeOrder();
        app(ShipmentService::class)->create($order, ['carrier' => 'manual', 'tracking_number' => 'A1'], $this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson("/api/v1/orders/{$order->id}/shipments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tracking_number', 'A1');

        $this->getJson('/api/v1/shipments?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.order_id', $order->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_rest_marks_an_existing_shipment_shipped(): void
    {
        $order = $this->makeOrder();
        $shipment = app(ShipmentService::class)->create($order, ['carrier' => 'manual'], $this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/shipments/{$shipment->id}/ship")
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped');
    }

    public function test_rest_refusals_are_422(): void
    {
        $order = $this->makeOrder();
        $line = $order->items->first();
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/orders/{$order->id}/shipments", [
            'items' => [['order_item_id' => $line->id, 'quantity' => 99]],
        ])->assertStatus(422)->assertJsonPath('error', 'shipping_error');
    }

    public function test_rest_permissions_and_token_abilities_are_enforced(): void
    {
        $order = $this->makeOrder();
        $viewer = $this->memberWith(['view_orders', 'view_shipments']);

        Sanctum::actingAs($viewer, ['*']);
        $this->getJson("/api/v1/orders/{$order->id}/shipments")->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/shipments", [])->assertForbidden();
    }

    public function test_a_read_only_token_cannot_create_shipments(): void
    {
        $order = $this->makeOrder();
        $token = $this->admin->createToken('ro', ['view_shipments'])->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/orders/{$order->id}/shipments")->assertOk();
        $this->withToken($token)->postJson("/api/v1/orders/{$order->id}/shipments", [])->assertForbidden();

        $this->assertSame(0, Shipment::count());
    }

    public function test_rest_hides_other_organizations_orders(): void
    {
        $other = \App\Models\Auth\Organization::create(['name' => 'O', 'email' => 'o@o.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $stranger = \App\Models\User::create([
            'name' => 'S', 'email' => 's@o.test', 'password' => bcrypt('x'), 'organization_id' => $other->id, 'role' => 'admin',
        ]);
        $order = $this->makeOrder();
        app(ShipmentService::class)->create($order, ['carrier' => 'manual'], $this->admin);

        Sanctum::actingAs($stranger, ['*']);
        $this->getJson("/api/v1/orders/{$order->id}/shipments")->assertNotFound();
        $this->getJson('/api/v1/shipments')->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- MCP ----------------------------------------------------------

    public function test_mcp_creates_a_manual_shipment(): void
    {
        $order = $this->makeOrder();

        InventorosServer::actingAs($this->admin)
            ->tool(CreateShipmentTool::class, [
                'order_id' => $order->id,
                'carrier_name' => 'UPS',
                'tracking_number' => '1Z999AA10123456784',
                'mark_shipped' => true,
            ])
            ->assertOk()
            ->assertSee('1Z999AA10123456784')
            ->assertSee('shipped');

        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_mcp_lists_shipments(): void
    {
        $order = $this->makeOrder();
        app(ShipmentService::class)->create($order, ['carrier' => 'manual', 'tracking_number' => 'LIST-ME'], $this->admin);

        InventorosServer::actingAs($this->admin)
            ->tool(ListShipmentsTool::class, ['order_id' => $order->id])
            ->assertOk()
            ->assertSee('LIST-ME');
    }

    public function test_mcp_shipment_tools_require_their_permissions(): void
    {
        $order = $this->makeOrder();
        $nobody = $this->memberWith(['view_orders'], 'nobody@ship.test');

        InventorosServer::actingAs($nobody)
            ->tool(ListShipmentsTool::class, [])
            ->assertHasErrors(['view_shipments']);

        InventorosServer::actingAs($nobody)
            ->tool(CreateShipmentTool::class, ['order_id' => $order->id])
            ->assertHasErrors(['create_shipments']);
    }

    public function test_mcp_reports_refusals_as_tool_errors(): void
    {
        $order = $this->makeOrder();
        app(ShipmentService::class)->create($order, ['carrier' => 'manual'], $this->admin);

        InventorosServer::actingAs($this->admin)
            ->tool(CreateShipmentTool::class, ['order_id' => $order->id])
            ->assertHasErrors(['already in a shipment']);
    }

    // ---- Outbound webhooks ---------------------------------------------

    public function test_shipment_events_are_advertised(): void
    {
        $this->assertContains('shipment.created', WebhookService::availableEvents());
        $this->assertContains('shipment.delivered', WebhookService::availableEvents());
        $this->assertArrayHasKey('Shipment', WebhookService::eventGroups());
    }

    public function test_shipment_created_and_delivered_dispatch_webhooks(): void
    {
        $this->actingAs($this->admin);
        foreach (['shipment.created', 'shipment.delivered'] as $event) {
            Webhook::create([
                'organization_id' => $this->organization->id, 'name' => $event, 'url' => 'https://example.com/hook',
                'secret' => 'shh', 'events' => [$event], 'is_active' => true, 'created_by' => $this->admin->id,
            ]);
        }
        Queue::fake();

        $service = app(ShipmentService::class);
        $shipment = $service->create($this->makeOrder(), ['carrier' => 'manual', 'tracking_number' => 'EVT-1'], $this->admin);
        $service->markShipped($shipment, $this->admin);
        $service->applyTrackingStatus($shipment->fresh(), ShipmentStatus::DELIVERED);

        $created = WebhookDelivery::where('event', 'shipment.created')->first();
        $delivered = WebhookDelivery::where('event', 'shipment.delivered')->first();

        $this->assertNotNull($created);
        $this->assertNotNull($delivered);
        $this->assertSame('EVT-1', $created->payload['data']['shipment']['tracking_number']);
        $this->assertSame($shipment->order->order_number, $created->payload['data']['order']['order_number']);
        $this->assertSame('delivered', $delivered->payload['data']['shipment']['status']);
        $this->assertArrayNotHasKey('carrier_response', $delivered->payload['data']['shipment']);
        Queue::assertPushed(WebhookDeliveryJob::class);
    }
}
