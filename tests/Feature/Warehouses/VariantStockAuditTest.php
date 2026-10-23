<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAudit;
use App\Services\ProductLocationStockService;
use App\Services\StockAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VariantStockAuditTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
        $this->productA->update(['has_variants' => true]);
        $this->variant = ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->productA->id,
            'sku' => 'SHIRT-BLUE', 'option_values' => ['Color' => 'Blue'], 'stock' => 20, 'is_active' => true,
        ]);
        DB::transaction(fn () => app(ProductLocationStockService::class)->move($this->variant, $this->locationA->id, $this->locationB->id, 7));
    }

    private function create(array $scope): StockAudit
    {
        return app(StockAuditService::class)->create($this->organization->id, $this->admin, $scope + ['name' => 'Count', 'audit_type' => 'full']);
    }

    public function test_location_count_finds_a_variant_stored_away_from_its_parents_primary_location(): void
    {
        $audit = $this->create(['warehouse_location_id' => $this->locationB->id]);
        $line = $audit->items()->where('product_variant_id', $this->variant->id)->sole();
        $this->assertSame(7, $line->system_quantity);
        $this->assertSame($this->locationB->id, $line->location_id);
        $this->assertFalse($audit->items()->where('product_id', $this->productA->id)->whereNull('product_variant_id')->exists());
    }

    public function test_warehouse_count_keeps_separate_lines_per_bin_and_only_reconciles_that_warehouse(): void
    {
        $overflow = ProductLocation::create(['organization_id' => $this->organization->id, 'warehouse_id' => $this->warehouseA->id, 'name' => 'Overflow', 'code' => 'A-2']);
        DB::transaction(fn () => app(ProductLocationStockService::class)->move($this->variant, $this->locationA->id, $overflow->id, 4));
        $audit = $this->create(['warehouse_id' => $this->warehouseA->id]);
        $this->assertCount(2, $audit->items);
        $this->assertSame(13, (int) $audit->items->sum('system_quantity'));
        $service = app(StockAuditService::class);
        $service->start($audit, $this->restricted);
        foreach ($audit->items as $item) {
            $service->recordCount($audit, $item, $this->restricted, $item->system_quantity - ($item->location_id === $this->locationA->id ? 2 : 0));
        }
        $this->assertSame(1, $service->complete($audit, $this->restricted));
        $this->assertSame(18, $this->variant->fresh()->stock);
        $this->assertSame(7, app(ProductLocationStockService::class)->onHandAt($this->variant, $this->locationB->id));
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertDatabaseHas('stock_adjustments', ['product_variant_id' => $this->variant->id, 'location_id' => $this->locationA->id, 'adjustment_quantity' => -2]);
    }

    public function test_start_resnapshots_variant_bins_after_stock_moves(): void
    {
        $audit = $this->create(['warehouse_id' => $this->warehouseA->id]);
        StockAdjustment::adjustVariant($this->variant, -3, 'damage', actor: $this->admin, locationId: $this->locationA->id);
        app(StockAuditService::class)->start($audit, $this->admin);
        $this->assertSame(10, $audit->items()->sole()->system_quantity);
    }

    public function test_whole_organization_count_uses_variant_totals_and_not_parent_stock(): void
    {
        $audit = $this->create([]);
        $line = $audit->items()->where('product_id', $this->productA->id)->sole();
        $this->assertSame($this->variant->id, $line->product_variant_id);
        $this->assertSame(20, $line->system_quantity);
    }

    public function test_warehouse_audits_are_accessible_only_to_that_warehouses_staff(): void
    {
        $audit = $this->create(['warehouse_id' => $this->warehouseB->id]);
        $this->actingAs($this->restricted)->get(route('stock-audits.show', $audit))->assertForbidden();
        $this->actingAs($this->restricted)->get(route('stock-audits.index'))->assertOk()->assertDontSee($audit->audit_number);
        $this->actingAs($this->restricted)->post(route('stock-audits.store'), [
            'name' => 'Alpha Count', 'audit_type' => 'full', 'warehouse_id' => $this->warehouseA->id,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_audits', ['name' => 'Alpha Count', 'warehouse_id' => $this->warehouseA->id]);
    }
    public function test_rest_api_accepts_a_warehouse_scope_and_honours_assignments(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->restricted);
        $response = $this->postJson('/api/v1/stock-audits', [
            'name' => 'API Count', 'audit_type' => 'full', 'warehouse_id' => $this->warehouseA->id,
        ])->assertCreated()->assertJsonPath('data.warehouse_id', $this->warehouseA->id);
        $this->getJson('/api/v1/stock-audits/'.$response->json('data.id'))->assertOk();
        $this->postJson('/api/v1/stock-audits', [
            'name' => 'Forbidden Count', 'audit_type' => 'full', 'warehouse_id' => $this->warehouseB->id,
        ])->assertForbidden();
    }

    public function test_a_location_moved_to_another_warehouse_invalidates_the_draft_scope(): void
    {
        $audit = $this->create(['warehouse_id' => $this->warehouseA->id]);
        $this->locationA->update(['warehouse_id' => $this->warehouseB->id]);
        $this->expectException(\App\Exceptions\InvalidStateException::class);
        app(StockAuditService::class)->start($audit, $this->admin);
    }

    public function test_assigned_staff_can_resume_a_warehouse_wide_count_but_not_another_warehouses_count(): void
    {
        $allowed = $this->create(['warehouse_id' => $this->warehouseA->id]);
        $denied = $this->create(['warehouse_id' => $this->warehouseB->id]);
        $service = app(StockAuditService::class);
        $service->start($allowed, $this->admin);
        $service->start($denied, $this->admin);
        $allowedItem = $allowed->items()->firstOrFail();
        $deniedItem = $denied->items()->firstOrFail();

        $this->actingAs($this->restricted)->postJson(route('stock-audits.items.count', [
            'stockAudit' => $allowed, 'item' => $allowedItem,
        ]), ['counted_quantity' => 8, 'expected_revision' => $allowedItem->count_revision])
            ->assertOk()->assertJsonPath('item.counted_quantity', 8);

        $this->postJson(route('stock-audits.items.count', [
            'stockAudit' => $denied, 'item' => $deniedItem,
        ]), ['counted_quantity' => 1, 'expected_revision' => $deniedItem->count_revision])
            ->assertForbidden()->assertJsonMissingPath('item');
        $this->assertNull($deniedItem->fresh()->counted_quantity);
    }

}
