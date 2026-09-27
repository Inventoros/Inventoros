<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Services\ProductLocationStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Optional warehouse/location capacity with utilisation, and warehouse
 * priority deciding which bins an order draws from first.
 */
class WarehouseCapacityAndPriorityTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();
    }

    // Capacity

    public function test_warehouse_capacity_can_be_set_on_web_and_api(): void
    {
        $this->actingAs($this->admin)
            ->put(route('warehouses.update', $this->warehouseA), [
                'name' => 'Alpha Warehouse', 'code' => 'WH-ALPHA', 'country' => 'CA', 'capacity' => 500,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(500, $this->warehouseA->fresh()->capacity);

        Sanctum::actingAs($this->admin, ['*']);
        $this->putJson("/api/v1/warehouses/{$this->warehouseB->id}", ['capacity' => 250])->assertOk();
        $this->assertSame(250, $this->warehouseB->fresh()->capacity);

        $this->putJson("/api/v1/warehouses/{$this->warehouseB->id}", ['capacity' => -1])->assertUnprocessable();
    }

    public function test_location_capacity_can_be_set_on_web_and_api(): void
    {
        $this->actingAs($this->admin)
            ->put(route('locations.update', $this->locationA), ['name' => 'Alpha Shelf', 'code' => 'A-1', 'capacity' => 80])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(80, $this->locationA->fresh()->capacity);

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson('/api/v1/locations', ['name' => 'Alpha Rack', 'code' => 'A-2', 'warehouse_id' => $this->warehouseA->id, 'capacity' => 40])
            ->assertCreated()
            ->assertJsonPath('data.capacity', 40)
            ->assertJsonPath('data.warehouse_id', $this->warehouseA->id);
    }

    public function test_show_page_reports_utilisation_from_the_warehouse_capacity(): void
    {
        $this->warehouseB->update(['capacity' => 180]);
        $this->locationB->update(['capacity' => 100]);

        $this->actingAs($this->admin)
            ->get(route('warehouses.show', $this->warehouseB))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.on_hand', 90)
                ->where('stats.capacity', 180)
                ->where('stats.utilisation', 50)
                ->where('locations.0.capacity', 100)
                ->where('locations.0.utilisation', 90)
            );
    }

    public function test_show_page_falls_back_to_the_sum_of_location_capacities(): void
    {
        $this->locationB->update(['capacity' => 120]);
        ProductLocation::create([
            'organization_id' => $this->organization->id, 'warehouse_id' => $this->warehouseB->id,
            'name' => 'Bravo Rack', 'code' => 'B-2', 'is_active' => true, 'capacity' => 60,
        ]);

        $this->actingAs($this->admin)
            ->get(route('warehouses.show', $this->warehouseB))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.capacity', 180)
                ->where('stats.utilisation', 50)
            );
    }

    public function test_show_page_reports_no_utilisation_without_any_capacity(): void
    {
        $this->actingAs($this->admin)
            ->get(route('warehouses.show', $this->warehouseB))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.capacity', null)
                ->where('stats.utilisation', null)
            );
    }

    // Priority-based fulfilment

    public function test_consume_draws_from_the_highest_priority_warehouse_first(): void
    {
        // productA: 60 at A (its primary location), 40 at B.
        $this->warehouseB->update(['priority' => 10]);

        $this->consume(30);

        $this->assertSame(60, $this->bin($this->locationA));
        $this->assertSame(10, $this->bin($this->locationB));
    }

    public function test_consume_spills_into_lower_priority_warehouses_and_keeps_totals_exact(): void
    {
        $this->warehouseB->update(['priority' => 10]);

        $this->consume(55);

        $this->assertSame(0, $this->bin($this->locationB));
        $this->assertSame(45, $this->bin($this->locationA));
        $this->assertSame(45, (int) ProductLocationStock::where('product_id', $this->productA->id)->sum('quantity'));
    }

    public function test_equal_priorities_keep_drawing_from_the_primary_location_first(): void
    {
        $this->consume(30);

        $this->assertSame(30, $this->bin($this->locationA));
        $this->assertSame(40, $this->bin($this->locationB));
    }

    public function test_within_a_warehouse_the_primary_location_still_goes_first(): void
    {
        $this->warehouseA->update(['priority' => 5]);
        $rack = ProductLocation::create([
            'organization_id' => $this->organization->id, 'warehouse_id' => $this->warehouseA->id,
            'name' => 'Alpha Rack', 'code' => 'A-2', 'is_active' => true,
        ]);
        ProductLocationStock::create(['organization_id' => $this->organization->id, 'product_id' => $this->productA->id, 'location_id' => $rack->id, 'quantity' => 90]);

        $this->consume(20);

        $this->assertSame(40, $this->bin($this->locationA));
        $this->assertSame(90, $this->bin($rack));
        $this->assertSame(40, $this->bin($this->locationB));
    }

    private function consume(int $quantity): void
    {
        DB::transaction(fn () => app(ProductLocationStockService::class)->consume($this->productA->fresh(), $quantity));
    }

    private function bin(ProductLocation $location): int
    {
        return (int) ProductLocationStock::where('product_id', $this->productA->id)->where('location_id', $location->id)->value('quantity');
    }
}
