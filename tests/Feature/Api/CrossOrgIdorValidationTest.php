<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\Supplier;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrossOrgIdorValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;
    protected Organization $orgB;
    protected User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->orgA = Organization::create(['name' => 'OrgA', 'email' => 'a@test.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->orgB = Organization::create(['name' => 'OrgB', 'email' => 'b@test.com', 'currency' => 'USD', 'timezone' => 'UTC']);

        $this->userA = User::create([
            'name' => 'UA', 'email' => 'ua@test.com', 'password' => bcrypt('x'),
            'organization_id' => $this->orgA->id, 'role' => 'admin',
        ]);
    }

    public function test_product_create_rejects_foreign_org_category_id(): void
    {
        $foreignCategory = ProductCategory::create([
            'organization_id' => $this->orgB->id,
            'name' => 'B-Cat', 'slug' => 'b-cat', 'is_active' => true,
        ]);

        Sanctum::actingAs($this->userA);

        $this->postJson('/api/v1/products', [
            'sku' => 'IDOR-SKU-1',
            'name' => 'Test',
            'price' => 10,
            'category_id' => $foreignCategory->id,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['category_id']);
    }

    public function test_product_create_rejects_foreign_org_location_id(): void
    {
        $foreignLocation = ProductLocation::create([
            'organization_id' => $this->orgB->id,
            'name' => 'B-Loc', 'code' => 'BL', 'is_active' => true,
        ]);

        Sanctum::actingAs($this->userA);

        $this->postJson('/api/v1/products', [
            'sku' => 'IDOR-SKU-2',
            'name' => 'Test',
            'price' => 10,
            'location_id' => $foreignLocation->id,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['location_id']);
    }

    public function test_category_create_rejects_foreign_org_parent_id(): void
    {
        $foreignParent = ProductCategory::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Foreign Parent', 'slug' => 'fp', 'is_active' => true,
        ]);

        Sanctum::actingAs($this->userA);

        $this->postJson('/api/v1/categories', [
            'name' => 'Child',
            'parent_id' => $foreignParent->id,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_purchase_order_create_rejects_foreign_org_supplier_and_product(): void
    {
        $foreignSupplier = Supplier::create(['organization_id' => $this->orgB->id, 'name' => 'B Sup']);
        $foreignProduct = Product::create([
            'organization_id' => $this->orgB->id,
            'sku' => 'B-PROD',
            'name' => 'B Product',
            'price' => 10, 'currency' => 'USD',
            'stock' => 0, 'min_stock' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->userA);

        $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $foreignSupplier->id,
            'order_date' => now()->toDateString(),
            'currency' => 'USD',
            'items' => [[
                'product_id' => $foreignProduct->id,
                'quantity' => 1,
                'unit_cost' => 5.0,
            ]],
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['supplier_id', 'items.0.product_id']);
    }

    public function test_stock_adjustment_create_rejects_foreign_org_product_id(): void
    {
        $foreignProduct = Product::create([
            'organization_id' => $this->orgB->id,
            'sku' => 'B-ADJ',
            'name' => 'Foreign',
            'price' => 1, 'currency' => 'USD', 'stock' => 10, 'min_stock' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->userA);

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $foreignProduct->id,
            'quantity' => 1,
            'type' => 'manual',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['product_id']);
    }
}
