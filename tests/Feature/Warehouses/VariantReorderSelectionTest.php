<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\WarehouseReorderPoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VariantReorderSelectionTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    private Product $jacket;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
        $this->jacket = Product::create([
            'organization_id' => $this->organization->id, 'name' => 'Jacket', 'sku' => 'JACKET',
            'price' => 10, 'stock' => 0, 'min_stock' => 0, 'has_variants' => true,
            'location_id' => $this->locationA->id, 'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->jacket->id,
            'sku' => 'JACKET-M', 'stock' => 100, 'is_active' => true, 'option_values' => ['Size' => 'M'],
        ]);
        WarehouseReorderPoint::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->jacket->id,
            'warehouse_id' => $this->warehouseA->id, 'min_stock' => 5, 'reorder_point' => 5, 'reorder_quantity' => 10,
        ]);
    }

    public function test_a_stocked_variant_warehouse_is_not_selected_as_empty_parent_stock(): void
    {
        $this->assertFalse(Product::whereKey($this->jacket->id)->needsReorder()->exists());
        $this->assertFalse(Product::whereKey($this->jacket->id)->lowStock()->exists());
    }

    public function test_variant_bin_shortfalls_trigger_the_warehouse_threshold(): void
    {
        DB::table('variant_location_stocks')->where('product_variant_id', $this->variant->id)->update(['quantity' => 4]);
        DB::table('product_variants')->where('id', $this->variant->id)->update(['stock' => 4]);
        $this->assertTrue(Product::whereKey($this->jacket->id)->needsReorder()->exists());
        $this->assertTrue(Product::whereKey($this->jacket->id)->lowStock()->exists());
    }

    public function test_inactive_and_deleted_variants_do_not_mask_a_shortfall(): void
    {
        $this->variant->update(['is_active' => false]);
        $this->assertTrue(Product::whereKey($this->jacket->id)->needsReorder()->exists());
        $this->variant->delete();
        $this->assertTrue(Product::whereKey($this->jacket->id)->needsReorder()->exists());
    }
}
