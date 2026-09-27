<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Auth\Organization;
use App\Models\User;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shipments over GraphQL: Order.shipments and the shipments query, gated like
 * the REST view_shipments routes (role AND token ability).
 */
class ShipmentGraphQLTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
    }

    private function shippedOrder()
    {
        $order = $this->makeOrder();
        $service = app(ShipmentService::class);
        $shipment = $service->create($order, [
            'carrier' => 'manual', 'carrier_name' => 'UPS', 'service' => 'Ground',
            'tracking_number' => '1Z999AA10123456784', 'cost' => '12.50',
        ], $this->admin);
        $service->markShipped($shipment, $this->admin);

        return $order;
    }

    private function gql(string $query)
    {
        return $this->postJson('/graphql', ['query' => $query]);
    }

    public function test_order_exposes_its_shipments_with_tracking_fields(): void
    {
        $order = $this->shippedOrder();
        Sanctum::actingAs($this->admin, ['*']);

        $this->gql("{ order(id: {$order->id}) { status approval_status shipments { id carrier carrier_name service tracking_number tracking_url status cost shipped_at delivered_at items { order_item_id product_name quantity } } } }")
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.order.status', 'shipped')
            ->assertJsonPath('data.order.approval_status', 'pending')
            ->assertJsonPath('data.order.shipments.0.carrier', 'manual')
            ->assertJsonPath('data.order.shipments.0.carrier_name', 'UPS')
            ->assertJsonPath('data.order.shipments.0.tracking_number', '1Z999AA10123456784')
            ->assertJsonPath('data.order.shipments.0.tracking_url', 'https://www.ups.com/track?tracknum=1Z999AA10123456784')
            ->assertJsonPath('data.order.shipments.0.status', 'shipped')
            ->assertJsonPath('data.order.shipments.0.cost', 12.5)
            ->assertJsonPath('data.order.shipments.0.items.0.quantity', 3)
            ->assertJsonPath('data.order.shipments.0.delivered_at', null);
    }

    public function test_order_shipments_are_null_without_view_shipments(): void
    {
        $order = $this->shippedOrder();
        $query = "{ order(id: {$order->id}) { order_number shipments { tracking_number } } }";

        Sanctum::actingAs($this->memberWith(['view_orders']), ['*']);
        $this->gql($query)
            ->assertJsonPath('data.order.order_number', $order->order_number)
            ->assertJsonPath('data.order.shipments', null);
    }

    public function test_order_shipments_are_null_for_a_token_without_view_shipments(): void
    {
        $order = $this->shippedOrder();
        $query = "{ order(id: {$order->id}) { order_number shipments { tracking_number } } }";

        // The admin holds every permission; the token only view_orders.
        $token = $this->admin->createToken('orders-only', ['view_orders'])->plainTextToken;
        $this->withToken($token)->postJson('/graphql', ['query' => $query])
            ->assertJsonPath('data.order.order_number', $order->order_number)
            ->assertJsonPath('data.order.shipments', null);
    }

    public function test_shipments_query_lists_and_filters(): void
    {
        $order = $this->shippedOrder();
        $other = $this->makeOrder([[$this->widget, 1]]);
        app(ShipmentService::class)->create($other, ['carrier' => 'manual', 'tracking_number' => 'PENDING-1'], $this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $this->gql('{ shipments { tracking_number order_id } }')
            ->assertJsonMissingPath('errors')
            ->assertJsonCount(2, 'data.shipments');

        $this->gql("{ shipments(order_id: {$order->id}) { tracking_number } }")
            ->assertJsonCount(1, 'data.shipments')
            ->assertJsonPath('data.shipments.0.tracking_number', '1Z999AA10123456784');

        $this->gql('{ shipments(status: "pending") { tracking_number status } }')
            ->assertJsonCount(1, 'data.shipments')
            ->assertJsonPath('data.shipments.0.tracking_number', 'PENDING-1');
    }

    public function test_shipments_query_needs_view_shipments(): void
    {
        $this->shippedOrder();

        Sanctum::actingAs($this->memberWith(['view_orders']), ['*']);
        $this->assertSame('Unauthorized', $this->gql('{ shipments { id } }')->json('errors.0.message'));
    }

    public function test_shipments_query_refuses_a_token_without_view_shipments(): void
    {
        $this->shippedOrder();

        // The admin holds every permission; the token only view_orders.
        $token = $this->admin->createToken('orders-only', ['view_orders'])->plainTextToken;
        $this->assertSame('Unauthorized', $this->withToken($token)->postJson('/graphql', ['query' => '{ shipments { id } }'])->json('errors.0.message'));
    }

    public function test_shipments_query_allows_a_token_with_view_shipments(): void
    {
        $this->shippedOrder();

        $allowed = $this->admin->createToken('shipments', ['view_shipments'])->plainTextToken;
        $this->withToken($allowed)->postJson('/graphql', ['query' => '{ shipments { id } }'])
            ->assertJsonMissingPath('errors')
            ->assertJsonCount(1, 'data.shipments');
    }

    public function test_shipments_query_is_tenant_scoped(): void
    {
        $this->shippedOrder();
        $other = Organization::create(['name' => 'Other', 'email' => 'o@o.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $stranger = User::create([
            'name' => 'S', 'email' => 's@o.test', 'password' => bcrypt('x'), 'organization_id' => $other->id, 'role' => 'admin',
        ]);
        Sanctum::actingAs($stranger, ['*']);

        $this->gql('{ shipments { id } }')->assertJsonMissingPath('errors')->assertJsonCount(0, 'data.shipments');
    }
}
