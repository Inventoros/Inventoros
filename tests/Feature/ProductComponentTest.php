<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductLocation;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductComponentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Organization $organization;
    protected ProductCategory $category;
    protected ProductLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'phone' => '123-456-7890',
            'address' => '123 Test St',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Category',
            'slug' => 'test-category',
            'is_active' => true,
        ]);

        $this->location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse A',
            'code' => 'WH-A',
            'is_active' => true,
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
                'description' => 'Full system access',
                'is_system' => true,
                'permissions' => [
                    'view_products',
                    'create_products',
                    'edit_products',
                    'delete_products',
                    'manage_stock',
                    'view_orders',
                    'create_orders',
                    'edit_orders',
                    'delete_orders',
                ],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    protected function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-' . uniqid(),
            'name' => 'Test Product',
            'price' => 99.99,
            'purchase_price' => 50.00,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'is_active' => true,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ], $attributes));
    }

    // ==================== ADD COMPONENT TESTS ====================

    public function test_can_add_component_to_kit_product(): void
    {
        $kit = $this->createProduct(['name' => 'Kit Product', 'type' => 'kit']);
        $component = $this->createProduct(['name' => 'Component A']);

        $response = $this->actingAs($this->admin)
            ->postJson(route('products.components.store', $kit), [
                'component_product_id' => $component->id,
                'quantity' => 2,
            ]);

        $response->assertStatus(201);
        $response->assertJson(['message' => 'Component added successfully.']);

        $this->assertDatabaseHas('product_components', [
            'parent_product_id' => $kit->id,
            'component_product_id' => $component->id,
            'quantity' => '2.00',
        ]);
    }

    public function test_can_add_component_to_assembly_product(): void
    {
        $assembly = $this->createProduct(['name' => 'Assembly Product', 'type' => 'assembly']);
        $component = $this->createProduct(['name' => 'Component B']);

        $response = $this->actingAs($this->admin)
            ->postJson(route('products.components.store', $assembly), [
                'component_product_id' => $component->id,
                'quantity' => 5,
            ]);

        $response->assertStatus(201);
        $response->assertJson(['message' => 'Component added successfully.']);

        $this->assertDatabaseHas('product_components', [
            'parent_product_id' => $assembly->id,
            'component_product_id' => $component->id,
            'quantity' => '5.00',
        ]);
    }

    public function test_cannot_add_component_to_standard_product(): void
    {
        $standard = $this->createProduct(['name' => 'Standard Product', 'type' => 'standard']);
        $component = $this->createProduct(['name' => 'Component C']);

        $response = $this->actingAs($this->admin)
            ->postJson(route('products.components.store', $standard), [
                'component_product_id' => $component->id,
                'quantity' => 1,
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_add_self_as_component(): void
    {
        $kit = $this->createProduct(['name' => 'Kit Product', 'type' => 'kit']);

        $response = $this->actingAs($this->admin)
            ->postJson(route('products.components.store', $kit), [
                'component_product_id' => $kit->id,
                'quantity' => 1,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('component_product_id');
    }

    // ==================== UPDATE COMPONENT TESTS ====================

    public function test_can_update_component_quantity(): void
    {
        $kit = $this->createProduct(['name' => 'Kit Product', 'type' => 'kit']);
        $component = $this->createProduct(['name' => 'Component A']);

        $pc = ProductComponent::create([
            'parent_product_id' => $kit->id,
            'component_product_id' => $component->id,
            'quantity' => 2,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson(route('products.components.update', [$kit, $pc]), [
                'quantity' => 10,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Component updated successfully.']);

        $this->assertDatabaseHas('product_components', [
            'id' => $pc->id,
            'quantity' => '10.00',
        ]);
    }

    // ==================== REMOVE COMPONENT TESTS ====================

    public function test_can_remove_component_from_kit(): void
    {
        $kit = $this->createProduct(['name' => 'Kit Product', 'type' => 'kit']);
        $component = $this->createProduct(['name' => 'Component A']);

        $pc = ProductComponent::create([
            'parent_product_id' => $kit->id,
            'component_product_id' => $component->id,
            'quantity' => 2,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson(route('products.components.destroy', [$kit, $pc]));

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Component removed successfully.']);

        $this->assertDatabaseMissing('product_components', [
            'id' => $pc->id,
        ]);
    }

    // ==================== LIST COMPONENTS TESTS ====================

    public function test_can_list_components_for_a_product(): void
    {
        $kit = $this->createProduct(['name' => 'Kit Product', 'type' => 'kit']);
        $compA = $this->createProduct(['name' => 'Component A']);
        $compB = $this->createProduct(['name' => 'Component B']);

        ProductComponent::create([
            'parent_product_id' => $kit->id,
            'component_product_id' => $compA->id,
            'quantity' => 2,
            'sort_order' => 0,
        ]);
        ProductComponent::create([
            'parent_product_id' => $kit->id,
            'component_product_id' => $compB->id,
            'quantity' => 3,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('products.components.index', $kit));

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'components');
    }

    // ==================== KIT AVAILABLE STOCK TESTS ====================

    public function test_kit_available_stock_is_calculated_correctly(): void
    {
        $kit = $this->createProduct(['name' => 'Kit Product', 'type' => 'kit', 'stock' => 0]);
        $compA = $this->createProduct(['name' => 'Component A', 'stock' => 20]);
        $compB = $this->createProduct(['name' => 'Component B', 'stock' => 9]);

        ProductComponent::create([
            'parent_product_id' => $kit->id,
            'component_product_id' => $compA->id,
            'quantity' => 2, // 20/2 = 10 kits possible
            'sort_order' => 0,
        ]);
        ProductComponent::create([
            'parent_product_id' => $kit->id,
            'component_product_id' => $compB->id,
            'quantity' => 3, // 9/3 = 3 kits possible
            'sort_order' => 1,
        ]);

        // Min of (20/2, 9/3) = min(10, 3) = 3
        $kit->load('components.componentProduct');
        $availableStock = $kit->components->map(function ($component) {
            return (int) floor($component->componentProduct->stock / $component->quantity);
        })->min();

        $this->assertEquals(3, $availableStock);
    }

    // ==================== VALIDATION TESTS ====================

    public function test_component_requires_valid_product_id(): void
    {
        $kit = $this->createProduct(['name' => 'Kit Product', 'type' => 'kit']);

        $response = $this->actingAs($this->admin)
            ->postJson(route('products.components.store', $kit), [
                'component_product_id' => 99999,
                'quantity' => 1,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('component_product_id');
    }

    // ==================== CROSS-ORG TESTS ====================

    public function test_cross_org_product_cannot_be_added_as_component(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-SKU',
            'name' => 'Other Org Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $kit = $this->createProduct(['name' => 'Kit Product', 'type' => 'kit']);

        $response = $this->actingAs($this->admin)
            ->postJson(route('products.components.store', $kit), [
                'component_product_id' => $otherProduct->id,
                'quantity' => 1,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('component_product_id');
    }
}
