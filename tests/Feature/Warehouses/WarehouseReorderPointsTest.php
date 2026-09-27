<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\WarehouseReorderPoint;
use App\Models\Notification;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Services\ReorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Per-warehouse reorder points: set on the product page, and used by
 * low-stock detection, notifications and reorder suggestions against the
 * product's on-hand in that warehouse.
 */
class WarehouseReorderPointsTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();
        Cache::flush();
    }

    private function levels(Product $product, int $warehouseId, array $values): WarehouseReorderPoint
    {
        return WarehouseReorderPoint::create(array_merge([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
        ], $values));
    }

    // Editing on the product page

    public function test_product_page_lists_each_accessible_warehouse_with_its_on_hand_and_levels(): void
    {
        $this->levels($this->productA, $this->warehouseB->id, ['reorder_point' => 45, 'reorder_quantity' => 30]);

        $this->actingAs($this->admin)
            ->get(route('products.show', $this->productA))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('warehouseStockLevels', 2)
                ->where('warehouseStockLevels.0.warehouse_name', 'Alpha Warehouse')
                ->where('warehouseStockLevels.0.on_hand', 60)
                ->where('warehouseStockLevels.0.reorder_point', null)
                ->where('warehouseStockLevels.1.warehouse_name', 'Bravo Warehouse')
                ->where('warehouseStockLevels.1.on_hand', 40)
                ->where('warehouseStockLevels.1.reorder_point', 45)
                ->where('warehouseStockLevels.1.reorder_quantity', 30)
                ->where('warehouseStockLevels.1.needs_reorder', true)
            );

        // A restricted user only sees their own warehouse.
        $this->actingAs($this->restricted)
            ->get(route('products.show', $this->productA))
            ->assertInertia(fn (Assert $page) => $page
                ->has('warehouseStockLevels', 1)
                ->where('warehouseStockLevels.0.warehouse_name', 'Alpha Warehouse')
            );
    }

    public function test_levels_can_be_saved_and_cleared_per_warehouse(): void
    {
        $this->actingAs($this->admin)
            ->put(route('products.warehouse-levels.update', $this->productA), [
                'levels' => [
                    ['warehouse_id' => $this->warehouseA->id, 'min_stock' => 10, 'reorder_point' => 20, 'reorder_quantity' => 50, 'max_stock' => 200],
                    ['warehouse_id' => $this->warehouseB->id, 'min_stock' => null, 'reorder_point' => null, 'reorder_quantity' => null, 'max_stock' => null],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('warehouse_reorder_points', [
            'product_id' => $this->productA->id, 'warehouse_id' => $this->warehouseA->id,
            'min_stock' => 10, 'reorder_point' => 20, 'reorder_quantity' => 50, 'max_stock' => 200,
            'organization_id' => $this->organization->id,
        ]);
        // An all-blank row stores nothing: that warehouse uses product-level values.
        $this->assertDatabaseMissing('warehouse_reorder_points', ['warehouse_id' => $this->warehouseB->id]);

        $this->actingAs($this->admin)
            ->put(route('products.warehouse-levels.update', $this->productA), [
                'levels' => [['warehouse_id' => $this->warehouseA->id, 'min_stock' => null, 'reorder_point' => null, 'reorder_quantity' => null, 'max_stock' => null]],
            ])
            ->assertRedirect();

        $this->assertSame(0, WarehouseReorderPoint::count());
    }

    public function test_levels_are_validated(): void
    {
        $this->actingAs($this->admin)
            ->put(route('products.warehouse-levels.update', $this->productA), [
                'levels' => [['warehouse_id' => $this->warehouseA->id, 'min_stock' => -1, 'max_stock' => 5, 'reorder_point' => 9]],
            ])
            ->assertSessionHasErrors(['levels.0.min_stock', 'levels.0.max_stock']);

        $this->assertSame(0, WarehouseReorderPoint::count());
    }

    public function test_restricted_user_cannot_set_levels_for_another_warehouse(): void
    {
        $this->actingAs($this->restricted)
            ->put(route('products.warehouse-levels.update', $this->productA), [
                'levels' => [['warehouse_id' => $this->warehouseB->id, 'reorder_point' => 45]],
            ])
            ->assertForbidden();

        $this->assertSame(0, WarehouseReorderPoint::count());
    }

    // Detection

    public function test_low_stock_scope_includes_products_low_in_one_warehouse(): void
    {
        // Total 100 is well above the product min of 5, but warehouse B holds 40.
        $this->assertFalse(Product::lowStock()->whereKey($this->productA->id)->exists());

        $this->levels($this->productA, $this->warehouseB->id, ['min_stock' => 45]);

        $this->assertTrue(Product::lowStock()->whereKey($this->productA->id)->exists());
        $this->assertFalse(Product::lowStock()->whereKey($this->productB->id)->exists());
    }

    public function test_needs_reorder_uses_warehouse_stock_with_product_level_fallback(): void
    {
        $this->assertFalse(Product::needsReorder()->whereKey($this->productA->id)->exists());

        // Row exists but leaves reorder_point blank: the product-level point
        // applies to that warehouse's stock.
        $this->productA->update(['reorder_point' => 45, 'reorder_quantity' => 25]);
        $this->assertFalse(Product::needsReorder()->whereKey($this->productA->id)->exists(), 'total 100 is above 45');

        $this->levels($this->productA, $this->warehouseB->id, ['min_stock' => 1]);
        $this->assertTrue(Product::needsReorder()->whereKey($this->productA->id)->exists(), 'warehouse B holds 40 <= 45');
    }

    public function test_suggested_quantity_sums_warehouse_shortfalls(): void
    {
        $this->levels($this->productA, $this->warehouseA->id, ['reorder_point' => 70, 'reorder_quantity' => 30]);
        // No reorder quantity: fill the gap up to max_stock (100 - 40).
        $this->levels($this->productA, $this->warehouseB->id, ['reorder_point' => 45, 'max_stock' => 100]);

        $reorder = app(ReorderService::class);

        $this->assertSame(90, $reorder->suggestedQuantity($this->productA->fresh()));
        $this->assertSame(
            ['Alpha Warehouse', 'Bravo Warehouse'],
            collect($reorder->warehouseShortfalls($this->productA->fresh()))->pluck('warehouse_name')->all()
        );
    }

    public function test_suggested_quantity_without_warehouse_levels_is_unchanged(): void
    {
        $this->productA->update(['reorder_quantity' => 12]);

        $this->assertSame(12, app(ReorderService::class)->suggestedQuantity($this->productA->fresh()));
    }

    public function test_reorder_command_orders_for_a_warehouse_shortfall(): void
    {
        $supplier = Supplier::create(['organization_id' => $this->organization->id, 'name' => 'Acme', 'is_active' => true]);
        $this->productA->suppliers()->attach($supplier->id, ['cost_price' => 2, 'is_primary' => true]);
        $this->levels($this->productA, $this->warehouseB->id, ['reorder_point' => 45, 'reorder_quantity' => 30]);

        $this->artisan('inventory:check-reorder-points')->assertExitCode(0);

        $po = PurchaseOrder::sole();
        $this->assertSame(30, (int) $po->items()->sole()->quantity_ordered);
    }

    public function test_dashboard_suggestions_name_the_short_warehouse(): void
    {
        $this->levels($this->productA, $this->warehouseB->id, ['reorder_point' => 45, 'reorder_quantity' => 30]);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reorderSuggestions.0.id', $this->productA->id)
                ->where('reorderSuggestions.0.suggested_quantity', 30)
                ->where('reorderSuggestions.0.warehouses', ['Bravo Warehouse'])
            );
    }

    // Notifications

    public function test_crossing_a_warehouse_minimum_notifies_stock_managers_who_can_see_it(): void
    {
        $this->levels($this->productB, $this->warehouseB->id, ['min_stock' => 45]);

        $bravoRole = Role::create(['name' => 'Bravo clerk', 'slug' => 'bravo-clerk', 'organization_id' => $this->organization->id, 'permissions' => ['manage_stock']]);
        $this->unassigned->roles()->attach($bravoRole->id);

        $bin = ProductLocationStock::where('product_id', $this->productB->id)->where('location_id', $this->locationB->id)->first();
        $bin->decrement('quantity', 10); // 50 -> 40, crosses 45

        $notified = Notification::where('type', 'warehouse_low_stock')->pluck('user_id')->all();

        $this->assertContains($this->unassigned->id, $notified);
        // Assigned to warehouse A only: warehouse B is none of their business.
        $this->assertNotContains($this->restricted->id, $notified);
        $this->assertStringContainsString('Bravo Warehouse', Notification::where('type', 'warehouse_low_stock')->first()->message);

        // Already low: a further drop does not alert again.
        $count = Notification::where('type', 'warehouse_low_stock')->count();
        $bin->decrement('quantity', 5);
        $this->assertSame($count, Notification::where('type', 'warehouse_low_stock')->count());
    }

    public function test_no_warehouse_levels_means_no_warehouse_notification(): void
    {
        $bin = ProductLocationStock::where('product_id', $this->productB->id)->where('location_id', $this->locationB->id)->first();
        $bin->decrement('quantity', 48);

        $this->assertSame(0, Notification::where('type', 'warehouse_low_stock')->count());
    }
}
