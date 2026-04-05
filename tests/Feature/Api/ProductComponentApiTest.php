<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductLocation;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductComponentApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Organization $organization;
    protected Product $kitProduct;
    protected Product $componentProductA;
    protected Product $componentProductB;
    protected Product $standardProduct;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse A',
            'code' => 'WH-A',
        ]);

        $this->kitProduct = Product::create([
            'organization_id' => $this->organization->id,
            'type' => 'kit',
            'sku' => 'KIT-001',
            'name' => 'Starter Kit',
            'price' => 149.99,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 5,
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);

        $this->componentProductA = Product::create([
            'organization_id' => $this->organization->id,
            'type' => 'standard',
            'sku' => 'COMP-A',
            'name' => 'Component A',
            'price' => 25.00,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);

        $this->componentProductB = Product::create([
            'organization_id' => $this->organization->id,
            'type' => 'standard',
            'sku' => 'COMP-B',
            'name' => 'Component B',
            'price' => 35.00,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 5,
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);

        $this->standardProduct = Product::create([
            'organization_id' => $this->organization->id,
            'type' => 'standard',
            'sku' => 'STD-001',
            'name' => 'Standard Product',
            'price' => 49.99,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 5,
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => [
                    'view_products',
                    'manage_products',
                ],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    // ==================== INDEX TESTS ====================

    public function test_can_list_components_for_kit_product(): void
    {
        Sanctum::actingAs($this->admin);

        ProductComponent::create([
            'parent_product_id' => $this->kitProduct->id,
            'component_product_id' => $this->componentProductA->id,
            'quantity' => 2,
            'sort_order' => 0,
        ]);

        ProductComponent::create([
            'parent_product_id' => $this->kitProduct->id,
            'component_product_id' => $this->componentProductB->id,
            'quantity' => 1,
            'sort_order' => 1,
        ]);

        $response = $this->getJson("/api/v1/products/{$this->kitProduct->id}/components");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'parent_product_id', 'component_product_id', 'quantity'],
                ],
            ]);
    }

    // ==================== STORE TESTS ====================

    public function test_can_add_component_to_kit(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/products/{$this->kitProduct->id}/components", [
            'component_product_id' => $this->componentProductA->id,
            'quantity' => 3,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Component added successfully')
            ->assertJsonPath('data.component_product_id', $this->componentProductA->id)
            ->assertJsonPath('data.quantity', '3.00');

        $this->assertDatabaseHas('product_components', [
            'parent_product_id' => $this->kitProduct->id,
            'component_product_id' => $this->componentProductA->id,
        ]);
    }

    public function test_cannot_add_component_to_standard_product(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/products/{$this->standardProduct->id}/components", [
            'component_product_id' => $this->componentProductA->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'invalid_product_type');
    }

    public function test_cannot_add_self_as_component(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/products/{$this->kitProduct->id}/components", [
            'component_product_id' => $this->kitProduct->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
    }

    // ==================== UPDATE TESTS ====================

    public function test_can_update_component_quantity(): void
    {
        Sanctum::actingAs($this->admin);

        $component = ProductComponent::create([
            'parent_product_id' => $this->kitProduct->id,
            'component_product_id' => $this->componentProductA->id,
            'quantity' => 2,
            'sort_order' => 0,
        ]);

        $response = $this->putJson("/api/v1/products/{$this->kitProduct->id}/components/{$component->id}", [
            'quantity' => 5,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Component updated successfully')
            ->assertJsonPath('data.quantity', '5.00');

        $this->assertDatabaseHas('product_components', [
            'id' => $component->id,
            'quantity' => 5,
        ]);
    }

    // ==================== DELETE TESTS ====================

    public function test_can_remove_component(): void
    {
        Sanctum::actingAs($this->admin);

        $component = ProductComponent::create([
            'parent_product_id' => $this->kitProduct->id,
            'component_product_id' => $this->componentProductA->id,
            'quantity' => 2,
            'sort_order' => 0,
        ]);

        $response = $this->deleteJson("/api/v1/products/{$this->kitProduct->id}/components/{$component->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Component removed successfully');

        $this->assertDatabaseMissing('product_components', [
            'id' => $component->id,
        ]);
    }

    // ==================== AUTH TESTS ====================

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->getJson("/api/v1/products/{$this->kitProduct->id}/components");

        $response->assertStatus(401);
    }
}
