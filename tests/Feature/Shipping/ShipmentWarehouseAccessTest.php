<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\ShipmentStatus;
use App\Models\Shipping\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Shipping\ShipmentService;
use App\Services\WarehouseAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Warehouse assignments apply to existing shipments as well as creation:
 * carrier purchases, shipping, cancellations and labels use the ship-from
 * warehouse even when someone else created the shipment.
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

    private function annexClerk(): User
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

    public static function inaccessibleShipments(): array
    {
        return [
            'another warehouse' => ['another'],
            'no warehouse on shipment' => ['none'],
            'unassigned user in restricted organization' => ['unassigned'],
        ];
    }

    #[DataProvider('inaccessibleShipments')]
    public function test_existing_shipment_actions_require_warehouse_access(string $case): void
    {
        $this->configureEasyPost();
        $shipment = app(ShipmentService::class)->create($this->makeRateableOrder(), ['carrier' => 'easypost'], $this->admin);
        $clerk = $this->memberWith(['view_orders', 'view_shipments', 'create_shipments']);

        if ($case === 'unassigned') {
            app(WarehouseAccessService::class)->setOrganizationRestrictsToAssigned($this->organization->id, true);
        } else {
            $clerk->warehouses()->attach($this->annex->id);
        }

        if ($case === 'none') {
            $shipment->forceFill(['warehouse_id' => null])->save();
        }

        Http::fake();
        Storage::fake('local');
        $original = $shipment->fresh()->getAttributes();

        $this->actingAs($clerk);
        foreach (['rates', 'buy', 'ship', 'cancel'] as $action) {
            $this->postJson(route('shipments.'.$action, $shipment), ['rate_id' => 'rate_example'])
                ->assertForbidden();
        }
        $this->getJson(route('shipments.label', $shipment))->assertForbidden();

        Sanctum::actingAs($clerk, ['*']);
        foreach (['rates', 'buy-label', 'ship', 'void-label'] as $action) {
            $this->postJson("/api/v1/shipments/{$shipment->id}/{$action}", ['rate_id' => 'rate_example'])
                ->assertForbidden();
        }
        $this->assertSame($original, $shipment->fresh()->getAttributes());
        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function unrestrictedUsers(): array
    {
        return [
            'administrator with different assignment' => ['admin'],
            'user with access_all_warehouses' => ['all'],
            'unassigned user with default organization setting' => ['unassigned'],
        ];
    }

    #[DataProvider('unrestrictedUsers')]
    public function test_unrestricted_users_can_act_on_existing_shipments(string $case): void
    {
        $shipment = app(ShipmentService::class)->create($this->makeOrder(), ['carrier' => 'manual'], $this->admin);

        if ($case === 'admin') {
            $actor = $this->admin;
            $actor->warehouses()->attach($this->annex->id);
            app(WarehouseAccessService::class)->setOrganizationRestrictsToAssigned($this->organization->id, true);
        } else {
            $permissions = ['view_orders', 'view_shipments', 'create_shipments'];
            if ($case === 'all') {
                $permissions[] = 'access_all_warehouses';
            }
            $actor = $this->memberWith($permissions);
            if ($case === 'all') {
                $actor->warehouses()->attach($this->annex->id);
                app(WarehouseAccessService::class)->setOrganizationRestrictsToAssigned($this->organization->id, true);
            }
        }

        // An unbound legacy shipment remains accessible to unrestricted users.
        $shipment->forceFill(['warehouse_id' => null])->save();
        Sanctum::actingAs($actor, ['*']);
        $this->getJson("/api/v1/shipments/{$shipment->id}")->assertOk();
        $this->postJson("/api/v1/shipments/{$shipment->id}/ship")
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped');

        $this->assertSame(ShipmentStatus::SHIPPED, $shipment->fresh()->status);
    }

    public function test_assigned_user_can_quote_buy_download_and_void_own_warehouse_label(): void
    {
        $this->configureEasyPost();
        Storage::fake('local');
        Http::fake([
            'api.easypost.com/v2/shipments' => Http::response($this->easyPostFixture('shipment_with_rates'), 201),
            'api.easypost.com/v2/shipments/*/buy' => Http::response($this->easyPostFixture('shipment_bought')),
            'api.easypost.com/v2/shipments/*/refund' => Http::response($this->easyPostFixture('shipment_refund_submitted')),
            'easypost-files.s3.us-west-2.amazonaws.com/*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);
        $shipment = app(ShipmentService::class)->create($this->makeRateableOrder(), ['carrier' => 'easypost'], $this->admin);
        $clerk = $this->memberWith(['view_orders', 'view_shipments', 'create_shipments']);
        $clerk->warehouses()->attach($this->warehouse->id);

        $this->actingAs($clerk);
        $rateId = $this->postJson(route('shipments.rates', $shipment))->assertOk()->json('rates.0.id');
        $this->postJson(route('shipments.buy', $shipment), ['rate_id' => $rateId])
            ->assertOk()
            ->assertJsonPath('shipment.status', 'label_created');
        $this->get(route('shipments.label', $shipment))->assertOk()->assertContent('%PDF-1.4 label');
        $this->post(route('shipments.cancel', $shipment))->assertSessionHasNoErrors();

        $this->assertSame(ShipmentStatus::CANCELLED, $shipment->fresh()->status);
    }

    public function test_assigned_user_can_ship_and_cancel_own_existing_shipments_over_api(): void
    {
        $service = app(ShipmentService::class);
        $toShip = $service->create($this->makeOrder(), ['carrier' => 'manual'], $this->admin);
        $toCancel = $service->create($this->makeOrder(), ['carrier' => 'manual'], $this->admin);
        $clerk = $this->memberWith(['view_orders', 'view_shipments', 'create_shipments']);
        $clerk->warehouses()->attach($this->warehouse->id);

        Sanctum::actingAs($clerk, ['*']);
        $this->postJson("/api/v1/shipments/{$toShip->id}/ship")
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped');
        $this->postJson("/api/v1/shipments/{$toCancel->id}/void-label")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }
}
