<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Exceptions\InsufficientStockException;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\VariantLocationStock;
use App\Services\OrderService;
use App\Services\ProductLocationStockService;
use App\Services\WarehouseStockLevelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VariantLocationStockTest extends TestCase
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
            'option_values' => ['Color' => 'Blue', 'Size' => 'M'], 'sku' => 'ALPHA-BLUE-M', 'title' => 'Blue / M', 'stock' => 20, 'is_active' => true,
        ]);
    }

    private function bins(): ProductLocationStockService
    {
        return app(ProductLocationStockService::class);
    }

    private function move(int $quantity): void
    {
        DB::transaction(function () use ($quantity): void {
            $locked = ProductVariant::whereKey($this->variant->id)->lockForUpdate()->firstOrFail();
            $this->bins()->move($locked, $this->locationA->id, $this->locationB->id, $quantity);
        });
    }

    public function test_opening_stock_is_binned_once_without_changing_parent(): void
    {
        $this->bins()->ensureBinned($this->variant);
        $this->assertSame(20, $this->bins()->totalAssigned($this->variant));
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertSame(1, VariantLocationStock::where('product_variant_id', $this->variant->id)->count());
    }

    public function test_upgrade_backfills_existing_variant_stock_at_its_primary_location(): void
    {
        $migration = require database_path('migrations/2026_10_21_000001_create_variant_location_stocks.php');
        $migration->down();
        $migration->up();
        $this->assertSame(20, $this->bins()->onHandAt($this->variant, $this->locationA->id));
        $this->assertSame(20, $this->bins()->totalAssigned($this->variant));
    }

    public function test_move_changes_only_location_balances_and_preserves_variant_total(): void
    {
        $this->move(7);
        $this->assertSame(13, $this->bins()->onHandAt($this->variant, $this->locationA->id));
        $this->assertSame(7, $this->bins()->onHandAt($this->variant, $this->locationB->id));
        $this->assertSame(20, $this->variant->fresh()->stock);
        $this->assertSame(100, $this->productA->fresh()->stock);
    }

    public function test_a_short_source_rolls_back_the_move(): void
    {
        try {
            $this->move(21);
            $this->fail('A short bin must be refused');
        } catch (InsufficientStockException) {
            $this->assertSame(20, $this->bins()->onHandAt($this->variant, $this->locationA->id));
            $this->assertSame(0, $this->bins()->onHandAt($this->variant, $this->locationB->id));
        }
    }

    public function test_adjustments_update_the_named_variant_bin_and_total_together(): void
    {
        StockAdjustment::adjustVariant($this->variant, 4, 'received', actor: $this->admin, locationId: $this->locationB->id);
        StockAdjustment::adjustVariant($this->variant, -2, 'damage', allowNegative: false, actor: $this->admin, locationId: $this->locationB->id);
        $this->assertSame(22, $this->variant->fresh()->stock);
        $this->assertSame(20, $this->bins()->onHandAt($this->variant, $this->locationA->id));
        $this->assertSame(2, $this->bins()->onHandAt($this->variant, $this->locationB->id));
        $this->assertDatabaseHas('stock_adjustments', ['product_variant_id' => $this->variant->id, 'location_id' => $this->locationB->id, 'adjustment_quantity' => -2]);
    }

    public function test_order_uses_its_warehouse_and_cancellation_restores_variant_bins(): void
    {
        $this->move(7);
        $order = app(OrderService::class)->create([
            'customer_name' => 'Buyer', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouseB->id,
            'items' => [['product_id' => $this->productA->id, 'product_variant_id' => $this->variant->id, 'quantity' => 5, 'unit_price' => 10]],
        ], $this->admin);
        $this->assertSame(13, $this->bins()->onHandAt($this->variant, $this->locationA->id));
        $this->assertSame(2, $this->bins()->onHandAt($this->variant, $this->locationB->id));
        $this->assertSame(15, $this->variant->fresh()->stock);
        app(OrderService::class)->cancel($order, $this->admin);
        $this->assertSame(20, $this->bins()->totalAssigned($this->variant));
        $this->assertSame(20, $this->variant->fresh()->stock);
    }

    public function test_breakdown_respects_warehouse_assignments_and_warehouse_totals_use_variants(): void
    {
        $this->move(7);
        $this->assertSame([$this->locationA->id], $this->bins()->breakdown($this->variant, $this->restricted)->pluck('location_id')->all());
        $totals = app(WarehouseStockLevelService::class)->onHandByWarehouse($this->productA);
        $this->assertSame(13, $totals[$this->warehouseA->id]);
        $this->assertSame(7, $totals[$this->warehouseB->id]);
    }

    public function test_a_restricted_user_can_adjust_a_variant_at_an_accessible_bin(): void
    {
        $this->actingAs($this->restricted)->post(route('stock-adjustments.store'), [
            'product_id' => $this->productA->id, 'product_variant_id' => $this->variant->id,
            'location_id' => $this->locationA->id, 'type' => 'damage', 'adjustment_quantity' => -3, 'reason' => 'Damaged',
        ])->assertSessionHasNoErrors();
        $this->assertSame(17, $this->bins()->onHandAt($this->variant, $this->locationA->id));

        $this->actingAs($this->restricted)->post(route('stock-adjustments.store'), [
            'product_id' => $this->productA->id, 'product_variant_id' => $this->variant->id,
            'location_id' => $this->locationB->id, 'type' => 'damage', 'adjustment_quantity' => -1, 'reason' => 'Damaged',
        ])->assertForbidden();
        $this->assertSame(17, $this->variant->fresh()->stock);
    }
}
