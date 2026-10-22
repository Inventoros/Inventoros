<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Services\WarehouseDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class InventoryCleanupTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
    }

    public function test_zero_stock_products_do_not_prevent_deleting_an_empty_warehouse(): void
    {
        $this->productA->update(['stock' => 0]);
        $this->productB->update(['stock' => 0]);
        ProductLocationStock::query()->update(['quantity' => 0]);
        $this->actingAs($this->admin)->delete(route('warehouses.destroy', $this->warehouseB))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSoftDeleted($this->warehouseB);
        $this->assertNull($this->locationB->fresh()->warehouse_id);
        $this->assertNotNull($this->productB->fresh());
        $this->assertDatabaseCount('product_location_stocks', 3);
    }

    public function test_a_non_primary_bin_with_stock_blocks_both_web_and_rest_deletion(): void
    {
        $this->productB->update(['stock' => 0]);
        ProductLocationStock::where('product_id', $this->productB->id)->update(['quantity' => 0]);
        $this->actingAs($this->admin)->delete(route('warehouses.destroy', $this->warehouseB))->assertSessionHasErrors('warehouse');
        Sanctum::actingAs($this->admin);
        $this->deleteJson('/api/v1/warehouses/'.$this->warehouseB->id)->assertUnprocessable();
        $this->assertNotSoftDeleted($this->warehouseB);
    }

    public function test_an_open_stock_count_keeps_its_warehouse_available_until_it_is_finished(): void
    {
        $this->productB->update(['stock' => 0]);
        ProductLocationStock::where('location_id', $this->locationB->id)->update(['quantity' => 0]);
        $audit = $this->makeAudit($this->locationB, 'Count before warehouse retirement');
        $this->actingAs($this->admin)->delete(route('warehouses.destroy', $this->warehouseB))->assertSessionHasErrors('warehouse');
        $this->assertNotSoftDeleted($this->warehouseB);
        $audit->update(['status' => 'completed']);
        $this->actingAs($this->admin)->delete(route('warehouses.destroy', $this->warehouseB))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($this->warehouseB);
    }

    public function test_default_and_inaccessible_warehouses_remain_protected(): void
    {
        $this->actingAs($this->restricted)->delete(route('warehouses.destroy', $this->warehouseB))->assertForbidden();
        $this->actingAs($this->admin)->delete(route('warehouses.destroy', $this->warehouseA))->assertSessionHasErrors('warehouse');
        $this->assertNotSoftDeleted($this->warehouseA);
    }

    public function test_products_can_be_listed_and_deleted_in_batches_larger_than_fifteen(): void
    {
        $products = Product::factory()->count(30)->create(['organization_id' => $this->organization->id]);
        $this->actingAs($this->admin)->get(route('products.index', ['per_page' => 100]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.per_page', 100)->has('products.data', 32));
        $this->actingAs($this->admin)->get(route('products.index', ['per_page' => 999999]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.per_page', 15));
        $this->actingAs($this->admin)->post(route('products.bulk.delete'), ['ids' => $products->pluck('id')->all()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, Product::forOrganization($this->organization->id)->count());
        $this->assertSoftDeleted($products->first());
    }

    public function test_unbinned_variant_stock_still_blocks_an_otherwise_empty_warehouse(): void
    {
        $this->productB->update(['stock' => 0, 'has_variants' => true]);
        ProductLocationStock::where('product_id', $this->productB->id)->update(['quantity' => 0]);
        ProductLocationStock::where('location_id', $this->locationB->id)->update(['quantity' => 0]);
        ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->productB->id,
            'title' => 'Red', 'sku' => 'CLEANUP-RED', 'stock' => 2, 'is_active' => true, 'option_values' => ['Colour' => 'Red'],
        ]);
        $this->expectException(ValidationException::class);
        app(WarehouseDeletionService::class)->delete($this->warehouseB, $this->admin);
    }
}
