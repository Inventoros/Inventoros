<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Auth\Organization;
use App\Models\Shipping\Shipment;
use App\Models\User;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShipmentWebTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    public function test_the_order_page_lists_shipments_and_the_ship_panel_data(): void
    {
        $order = $this->makeOrder();
        app(ShipmentService::class)->create($order, ['carrier' => 'manual', 'carrier_name' => 'UPS', 'tracking_number' => '1Z999'], $this->admin);

        $this->actingAs($this->admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->has('shipments', 1)
                ->where('shipments.0.carrier_name', 'UPS')
                ->where('shipments.0.tracking_url', 'https://www.ups.com/track?tracknum=1Z999')
                ->missing('shipments.0.label_path')
                ->missing('shipments.0.carrier_response')
                ->where('shipping.carriers', ['manual'])
                ->where('shipping.canCreate', true)
                ->has('shipping.lines', 2)
                ->where('shipping.lines.0.remaining', 0)
                ->has('shipping.warehouses')
                ->has('shipping.toAddress')
            );
    }

    public function test_members_without_view_shipments_get_no_shipping_data(): void
    {
        $order = $this->makeOrder();
        $member = $this->memberWith(['view_orders']);

        $this->actingAs($member)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shipments', null)
                ->where('shipping', null)
            );
    }

    public function test_manual_tracking_can_be_entered_and_marked_shipped_in_one_step(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)
            ->from(route('orders.show', $order))
            ->post(route('orders.shipments.store', $order), [
                'carrier' => 'manual',
                'carrier_name' => 'UPS',
                'service' => 'Ground',
                'tracking_number' => '1Z999AA10123456784',
                'cost' => '12.50',
                'mark_shipped' => true,
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        $shipment = Shipment::firstOrFail();
        $this->assertSame(ShipmentStatus::SHIPPED, $shipment->status);
        $this->assertSame('https://www.ups.com/track?tracknum=1Z999AA10123456784', $shipment->tracking_url);
        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_selected_lines_and_quantities_create_a_partial_shipment(): void
    {
        $order = $this->makeOrder();
        $line = $order->items->firstWhere('product_id', $this->widget->id);

        $this->actingAs($this->admin)
            ->post(route('orders.shipments.store', $order), [
                'carrier' => 'manual',
                'items' => [['order_item_id' => $line->id, 'quantity' => 2]],
                'mark_shipped' => true,
            ])
            ->assertSessionHas('success');

        $this->assertSame(2, (int) Shipment::firstOrFail()->items->sum('quantity'));
        $this->assertSame(OrderStatus::PROCESSING, $order->fresh()->status);
    }

    public function test_over_shipping_is_flashed_as_an_error(): void
    {
        $order = $this->makeOrder();
        $line = $order->items->firstWhere('product_id', $this->widget->id);

        $this->actingAs($this->admin)
            ->from(route('orders.show', $order))
            ->post(route('orders.shipments.store', $order), [
                'carrier' => 'manual',
                'items' => [['order_item_id' => $line->id, 'quantity' => 9]],
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('error');

        $this->assertSame(0, Shipment::count());
    }

    public function test_creating_shipments_requires_create_shipments(): void
    {
        $order = $this->makeOrder();
        $member = $this->memberWith(['view_orders', 'view_shipments']);

        $this->actingAs($member)
            ->post(route('orders.shipments.store', $order), ['carrier' => 'manual'])
            ->assertForbidden();

        $this->assertSame(0, Shipment::count());
    }

    public function test_an_easypost_shipment_returns_rates_and_buys_a_label_over_json(): void
    {
        $this->configureEasyPost();
        $order = $this->makeRateableOrder();
        Http::fake([
            'api.easypost.com/v2/shipments' => Http::response($this->easyPostFixture('shipment_with_rates'), 201),
            'api.easypost.com/v2/shipments/*/buy' => Http::response($this->easyPostFixture('shipment_bought')),
            'easypost-files.s3.us-west-2.amazonaws.com/*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $created = $this->actingAs($this->admin)
            ->postJson(route('orders.shipments.store', $order), ['carrier' => 'easypost', 'weight_oz' => 16])
            ->assertCreated()
            ->assertJsonPath('rates.0.carrier', 'USPS')
            ->assertJsonPath('rates.0.amount', '7.58')
            ->json();

        $this->actingAs($this->admin)
            ->postJson(route('shipments.buy', $created['shipment']['id']), ['rate_id' => 'rate_3f1b2c4d5e6f4a7b8c9d0e1f2a3b4c5d'])
            ->assertOk()
            ->assertJsonPath('shipment.status', 'label_created')
            ->assertJsonPath('shipment.tracking_number', '9400100208271109836487')
            ->assertJsonPath('shipment.has_label', true);

        $this->actingAs($this->admin)
            ->get(route('shipments.label', $created['shipment']['id']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_carrier_errors_come_back_as_a_422_message(): void
    {
        $this->configureEasyPost();
        $order = $this->makeRateableOrder();
        Http::fake(['api.easypost.com/v2/shipments' => Http::response($this->easyPostFixture('error_address'), 422)]);

        $this->actingAs($this->admin)
            ->postJson(route('orders.shipments.store', $order), ['carrier' => 'easypost', 'weight_oz' => 16])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unable to verify address. Address not found.')
            ->assertJsonPath('shipment.status', 'pending');
    }

    public function test_a_pending_shipment_can_be_marked_shipped_and_another_cancelled(): void
    {
        $order = $this->makeOrder();
        $line = $order->items->firstWhere('product_id', $this->widget->id);
        $service = app(ShipmentService::class);
        $first = $service->create($order, ['carrier' => 'manual', 'items' => [['order_item_id' => $line->id, 'quantity' => 1]]], $this->admin);
        $second = $service->create($order, ['carrier' => 'manual'], $this->admin);

        $this->actingAs($this->admin)->post(route('shipments.ship', $first))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('shipments.cancel', $second))->assertSessionHas('success');

        $this->assertSame(ShipmentStatus::SHIPPED, $first->fresh()->status);
        $this->assertSame(ShipmentStatus::CANCELLED, $second->fresh()->status);
    }

    public function test_shipments_of_another_organization_are_not_reachable(): void
    {
        $order = $this->makeOrder();
        $shipment = app(ShipmentService::class)->create($order, ['carrier' => 'manual'], $this->admin);

        $other = Organization::create(['name' => 'Other', 'email' => 'o@o.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $stranger = User::create([
            'name' => 'S', 'email' => 's@o.test', 'password' => bcrypt('x'), 'organization_id' => $other->id, 'role' => 'admin',
        ]);

        $this->actingAs($stranger)->post(route('shipments.ship', $shipment))->assertNotFound();
        $this->actingAs($stranger)->get(route('shipments.label', $shipment))->assertNotFound();
        $this->actingAs($stranger)->post(route('orders.shipments.store', $order), ['carrier' => 'manual'])->assertNotFound();

        $this->assertSame(ShipmentStatus::PENDING, $shipment->fresh()->status);
    }

    public function test_label_download_requires_view_shipments(): void
    {
        $order = $this->makeOrder();
        $shipment = app(ShipmentService::class)->create($order, ['carrier' => 'manual'], $this->admin);

        $this->actingAs($this->memberWith(['view_orders']))
            ->get(route('shipments.label', $shipment))
            ->assertForbidden();
    }
}
