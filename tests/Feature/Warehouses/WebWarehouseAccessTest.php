<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A user assigned to warehouse A must not see or act on anything in
 * warehouse B through the web app.
 */
class WebWarehouseAccessTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();
    }

    // Locations

    public function test_location_list_hides_other_warehouses(): void
    {
        $this->actingAs($this->restricted)
            ->get(route('locations.index'))
            ->assertOk()
            ->assertSee('Alpha Shelf')
            ->assertDontSee('Bravo Shelf');
    }

    public function test_cannot_update_or_delete_a_location_in_another_warehouse(): void
    {
        $this->actingAs($this->restricted)
            ->put(route('locations.update', $this->locationB), ['name' => 'Hijacked', 'code' => 'B-1'])
            ->assertForbidden();

        $this->actingAs($this->restricted)
            ->delete(route('locations.destroy', $this->locationB))
            ->assertForbidden();

        $this->assertDatabaseHas('product_locations', ['id' => $this->locationB->id, 'name' => 'Bravo Shelf', 'deleted_at' => null]);
    }

    public function test_cannot_create_a_location_in_another_warehouse(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('locations.store'), ['name' => 'Sneaky', 'code' => 'B-9', 'warehouse_id' => $this->warehouseB->id])
            ->assertForbidden();

        $this->assertDatabaseMissing('product_locations', ['code' => 'B-9']);

        // No warehouse at all would put it outside every assignment.
        $this->actingAs($this->restricted)
            ->post(route('locations.store'), ['name' => 'Floating', 'code' => 'F-1'])
            ->assertForbidden();
    }

    public function test_can_create_a_location_in_own_warehouse(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('locations.store'), ['name' => 'Alpha Rack', 'code' => 'A-2', 'warehouse_id' => $this->warehouseA->id, 'capacity' => 30])
            ->assertRedirect(route('locations.index'));

        $this->assertDatabaseHas('product_locations', ['code' => 'A-2', 'warehouse_id' => $this->warehouseA->id, 'capacity' => 30]);
    }

    // Stock adjustments

    public function test_cannot_adjust_stock_at_a_location_in_another_warehouse(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $this->productA->id,
                'type' => 'damage',
                'adjustment_quantity' => -5,
                'reason' => 'Broken',
                'location_id' => $this->locationB->id,
            ])
            ->assertForbidden();

        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertSame(40, $this->bin($this->productA->id, $this->locationB->id));
    }

    public function test_restricted_user_must_name_a_location_they_can_access(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $this->productA->id,
                'type' => 'damage',
                'adjustment_quantity' => -5,
                'reason' => 'Broken',
            ])
            ->assertForbidden();

        $this->assertSame(100, $this->productA->fresh()->stock);
    }

    public function test_can_adjust_stock_in_own_warehouse_and_the_adjustment_records_its_location(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $this->productA->id,
                'type' => 'damage',
                'adjustment_quantity' => -5,
                'reason' => 'Broken',
                'location_id' => $this->locationA->id,
            ])
            ->assertRedirect(route('stock-adjustments.index'));

        $this->assertSame(95, $this->productA->fresh()->stock);
        $this->assertSame(55, $this->bin($this->productA->id, $this->locationA->id));
        $this->assertDatabaseHas('stock_adjustments', ['product_id' => $this->productA->id, 'location_id' => $this->locationA->id]);
    }

    public function test_unassigned_user_keeps_access_to_every_warehouse(): void
    {
        $this->actingAs($this->unassigned)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $this->productA->id,
                'type' => 'damage',
                'adjustment_quantity' => -5,
                'reason' => 'Broken',
                'location_id' => $this->locationB->id,
            ])
            ->assertRedirect(route('stock-adjustments.index'));

        $this->assertSame(35, $this->bin($this->productA->id, $this->locationB->id));
    }

    public function test_adjustment_history_hides_other_warehouses(): void
    {
        $this->makeAdjustment($this->productA, $this->locationA, 'Alpha recount');
        $bravo = $this->makeAdjustment($this->productA, $this->locationB, 'Bravo recount');

        $this->actingAs($this->restricted)
            ->get(route('stock-adjustments.index'))
            ->assertOk()
            ->assertSee('Alpha recount')
            ->assertDontSee('Bravo recount');

        $this->actingAs($this->restricted)
            ->get(route('stock-adjustments.show', $bravo))
            ->assertForbidden();
    }

    // Transfers

    public function test_transfer_list_and_detail_hide_other_warehouses(): void
    {
        $mine = $this->makeTransfer($this->locationA, $this->locationA, $this->productA);
        $theirs = $this->makeTransfer($this->locationB, $this->locationB, $this->productB);

        $this->actingAs($this->restricted)
            ->get(route('stock-transfers.index'))
            ->assertOk()
            ->assertSee($mine->transfer_number)
            ->assertDontSee($theirs->transfer_number);

        $this->actingAs($this->restricted)->get(route('stock-transfers.show', $theirs))->assertForbidden();
        $this->actingAs($this->restricted)->post(route('stock-transfers.complete', $theirs))->assertForbidden();
        $this->actingAs($this->restricted)->post(route('stock-transfers.cancel', $theirs))->assertForbidden();

        $this->assertSame('pending', $theirs->fresh()->status);
    }

    public function test_cannot_create_a_transfer_out_of_another_warehouse(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('stock-transfers.store'), [
                'from_location_id' => $this->locationB->id,
                'to_location_id' => $this->locationA->id,
                'items' => [['product_id' => $this->productA->id, 'quantity' => 5]],
            ])
            ->assertForbidden();

        $this->assertSame(0, StockTransfer::count());
    }

    public function test_can_send_stock_from_own_warehouse_to_another(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('stock-transfers.store'), [
                'from_location_id' => $this->locationA->id,
                'to_location_id' => $this->locationB->id,
                'items' => [['product_id' => $this->productA->id, 'quantity' => 5]],
            ])
            ->assertRedirect();

        $this->assertSame(1, StockTransfer::count());
    }

    // Stock audits

    public function test_audit_list_and_detail_hide_other_warehouses(): void
    {
        $this->makeAudit($this->locationA, 'Alpha cycle count');
        $theirs = $this->makeAudit($this->locationB, 'Bravo cycle count');
        $this->makeAudit(null, 'Whole org count');

        $this->actingAs($this->restricted)
            ->get(route('stock-audits.index'))
            ->assertOk()
            ->assertSee('Alpha cycle count')
            ->assertDontSee('Bravo cycle count')
            ->assertDontSee('Whole org count');

        $this->actingAs($this->restricted)->get(route('stock-audits.show', $theirs))->assertForbidden();
        $this->actingAs($this->restricted)->post(route('stock-audits.start', $theirs))->assertForbidden();
        $this->actingAs($this->restricted)->delete(route('stock-audits.destroy', $theirs))->assertForbidden();

        $this->assertSame('draft', $theirs->fresh()->status);
    }

    public function test_cannot_create_an_audit_for_another_warehouse_or_the_whole_org(): void
    {
        foreach ([$this->locationB->id, null] as $locationId) {
            $this->actingAs($this->restricted)
                ->post(route('stock-audits.store'), [
                    'name' => 'Sneaky count',
                    'audit_type' => 'cycle',
                    'warehouse_location_id' => $locationId,
                ])
                ->assertForbidden();
        }

        $this->assertSame(0, StockAudit::count());
    }

    // Purchase order receiving

    public function test_cannot_receive_goods_into_another_warehouse(): void
    {
        [$po, $item] = $this->makeSentPurchaseOrder($this->productB);

        $this->actingAs($this->restricted)
            ->post(route('purchase-orders.process-receiving', $po), [
                'items' => [['id' => $item->id, 'quantity_to_receive' => 4]],
            ])
            ->assertForbidden();

        $this->assertSame(50, $this->productB->fresh()->stock);
        $this->assertSame(0, $item->fresh()->quantity_received);
    }

    public function test_can_receive_goods_into_own_warehouse(): void
    {
        [$po, $item] = $this->makeSentPurchaseOrder($this->productA);

        $this->actingAs($this->restricted)
            ->post(route('purchase-orders.process-receiving', $po), [
                'items' => [['id' => $item->id, 'quantity_to_receive' => 4]],
            ])
            ->assertRedirect(route('purchase-orders.show', $po));

        $this->assertSame(4, $item->fresh()->quantity_received);
    }

    // Products and warehouses

    public function test_product_page_breakdown_only_shows_accessible_bins(): void
    {
        $this->actingAs($this->restricted)
            ->get(route('products.show', $this->productA))
            ->assertOk()
            ->assertSee('Alpha Shelf')
            ->assertDontSee('Bravo Shelf');
    }

    public function test_warehouse_pages_hide_other_warehouses(): void
    {
        $this->actingAs($this->restricted)
            ->get(route('warehouses.index'))
            ->assertOk()
            ->assertSee('WH-ALPHA')
            ->assertDontSee('WH-BRAVO');

        $this->actingAs($this->restricted)->get(route('warehouses.show', $this->warehouseB))->assertForbidden();
    }

    public function test_cannot_switch_into_another_warehouse(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('warehouses.set-active'), ['warehouse_id' => $this->warehouseB->id])
            ->assertForbidden();

        $this->actingAs($this->restricted)
            ->post(route('warehouses.set-active'), ['warehouse_id' => $this->warehouseA->id])
            ->assertRedirect();
    }

    private function bin(int $productId, int $locationId): int
    {
        return (int) ProductLocationStock::where('product_id', $productId)->where('location_id', $locationId)->value('quantity');
    }
}
