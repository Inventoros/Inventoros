<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipping\Shipment;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A user restricted to some warehouses cannot create a shipment from a
 * warehouse they were not given (the ship-from warehouse is where the goods
 * leave, and its address goes on the label).
 */
class ShipmentWarehouseAccessTest extends TestCase
{
    use RefreshDatabase, ShippingTestHelpers;

    private Warehouse $annex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();

        $this->annex = Warehouse::create([
            'organization_id' => $this->organization->id, 'name' => 'Annex', 'code' => 'ANX',
            'is_default' => false, 'is_active' => true,
        ]);
    }

    private function annexClerk()
    {
        $clerk = $this->memberWith(['view_orders', 'view_shipments', 'create_shipments']);
        $clerk->warehouses()->attach($this->annex->id);

        return $clerk;
    }

    public function test_a_restricted_user_cannot_ship_from_a_warehouse_they_were_not_given(): void
    {
        $order = $this->makeOrder();
        $clerk = $this->annexClerk();

        $this->actingAs($clerk)
            ->post(route('orders.shipments.store', $order), ['carrier' => 'manual'])
            ->assertForbidden();

        $this->actingAs($clerk)
            ->post(route('orders.shipments.store', $order), ['carrier' => 'manual', 'warehouse_id' => $this->warehouse->id])
            ->assertForbidden();

        $this->assertSame(0, Shipment::count());
    }

    public function test_the_api_refuses_it_too(): void
    {
        $order = $this->makeOrder();

        Sanctum::actingAs($this->annexClerk(), ['*']);
        $this->postJson("/api/v1/orders/{$order->id}/shipments", ['warehouse_id' => $this->warehouse->id])
            ->assertForbidden();

        $this->assertSame(0, Shipment::count());
    }

    public function test_a_restricted_user_can_ship_from_their_own_warehouse(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->annexClerk())
            ->post(route('orders.shipments.store', $order), ['carrier' => 'manual', 'warehouse_id' => $this->annex->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->annex->id, Shipment::sole()->warehouse_id);
    }
}
