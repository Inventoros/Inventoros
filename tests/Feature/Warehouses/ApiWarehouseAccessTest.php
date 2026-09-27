<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\StockAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The REST API applies the same warehouse restriction as the web app.
 */
class ApiWarehouseAccessTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
        Sanctum::actingAs($this->restricted, ['*']);
    }

    public function test_location_endpoints_hide_other_warehouses(): void
    {
        $this->getJson('/api/v1/locations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Shelf');

        $this->getJson("/api/v1/locations/{$this->locationB->id}")->assertForbidden();
        $this->putJson("/api/v1/locations/{$this->locationB->id}", ['name' => 'Hijacked', 'code' => 'B-1'])->assertForbidden();
        $this->deleteJson("/api/v1/locations/{$this->locationB->id}")->assertForbidden();
        $this->postJson('/api/v1/locations', ['name' => 'Sneaky', 'code' => 'B-9', 'warehouse_id' => $this->warehouseB->id])->assertForbidden();

        $this->assertDatabaseHas('product_locations', ['id' => $this->locationB->id, 'name' => 'Bravo Shelf', 'deleted_at' => null]);
        $this->assertDatabaseMissing('product_locations', ['code' => 'B-9']);
    }

    public function test_cannot_adjust_stock_in_another_warehouse(): void
    {
        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->productA->id,
            'quantity' => -5,
            'type' => 'damage',
            'location_id' => $this->locationB->id,
        ])->assertForbidden();

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->productA->id,
            'quantity' => -5,
            'type' => 'damage',
        ])->assertForbidden();

        $this->assertSame(100, $this->productA->fresh()->stock);

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->productA->id,
            'quantity' => -5,
            'type' => 'damage',
            'location_id' => $this->locationA->id,
        ])->assertCreated();

        $this->assertSame(95, $this->productA->fresh()->stock);
    }

    public function test_adjustment_history_hides_other_warehouses(): void
    {
        $this->makeAdjustment($this->productA, $this->locationA, 'Alpha recount');
        $bravo = $this->makeAdjustment($this->productA, $this->locationB, 'Bravo recount');

        $this->getJson('/api/v1/stock-adjustments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Alpha recount');

        $this->getJson("/api/v1/stock-adjustments/{$bravo->id}")->assertForbidden();
    }

    public function test_stock_audits_hide_other_warehouses(): void
    {
        $this->makeAudit($this->locationA, 'Alpha cycle count');
        $theirs = $this->makeAudit($this->locationB, 'Bravo cycle count');

        $this->getJson('/api/v1/stock-audits')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha cycle count');

        $this->getJson("/api/v1/stock-audits/{$theirs->id}")->assertForbidden();
    }

    public function test_cannot_receive_purchase_order_into_another_warehouse(): void
    {
        [$po, $item] = $this->makeSentPurchaseOrder($this->productB);

        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [['id' => $item->id, 'quantity_to_receive' => 3]],
        ])->assertForbidden();

        $this->assertSame(0, $item->fresh()->quantity_received);
        $this->assertSame(50, $this->productB->fresh()->stock);
    }

    public function test_warehouse_endpoints_hide_other_warehouses(): void
    {
        $this->getJson('/api/v1/warehouses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'WH-ALPHA');

        $this->getJson("/api/v1/warehouses/{$this->warehouseB->id}")->assertForbidden();
    }

    public function test_unassigned_user_sees_every_warehouse(): void
    {
        Sanctum::actingAs($this->unassigned, ['*']);

        $this->getJson('/api/v1/locations')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(0, StockAdjustment::count());
    }
}
