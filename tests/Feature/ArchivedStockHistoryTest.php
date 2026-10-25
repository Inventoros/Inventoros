<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArchivedStockHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_products_and_variants_remain_attached_to_the_ledger(): void
    {
        $organization = Organization::factory()->create();
        $product = Product::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'organization_id' => $organization->id, 'sku' => 'OLD-VAR', 'title' => 'Old variant', 'option_values' => ['Size' => 'M']]);
        $adjustment = StockAdjustment::create([
            'organization_id' => $organization->id, 'product_id' => $product->id, 'user_id' => $user->id,
            'product_variant_id' => $variant->id, 'type' => 'manual',
            'quantity_before' => 2, 'quantity_after' => 1, 'adjustment_quantity' => -1,
        ]);
        $variant->delete();
        $product->delete();
        $this->assertTrue($adjustment->fresh()->product->trashed());
        $this->assertTrue($adjustment->fresh()->variant->trashed());
    }

    public function test_history_can_be_viewed_searched_and_filtered_without_restoring_products(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $admin->forceFill(['role' => 'admin'])->save();
        $product = Product::factory()->create(['organization_id' => $organization->id, 'name' => 'Archived item', 'sku' => 'OLD-SKU']);
        StockAdjustment::create([
            'organization_id' => $organization->id, 'product_id' => $product->id, 'user_id' => $admin->id,
            'type' => 'manual', 'quantity_before' => 2, 'quantity_after' => 1, 'adjustment_quantity' => -1,
        ]);
        $product->delete();
        foreach (['search' => 'OLD-SKU', 'product_id' => $product->id] as $key => $value) {
            $this->actingAs($admin)->get(route('stock-adjustments.index', [$key => $value]))
                ->assertOk()->assertInertia(fn ($page) => $page
                ->has('adjustments.data', 1)->where('adjustments.data.0.product.name', 'Archived item')
                ->where('products.0.id', $product->id));
        }
        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/v1/stock-adjustments')->assertOk()->assertJsonPath('data.0.product.sku', 'OLD-SKU');
        $this->assertNull(Product::find($product->id));
        $other = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $other->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($other, ['*']);
        $this->getJson('/api/v1/stock-adjustments')->assertOk()->assertJsonCount(0, 'data');
    }
}
