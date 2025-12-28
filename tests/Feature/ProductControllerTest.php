<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected User $viewOnlyUser;
    protected Organization $organization;
    protected ProductCategory $category;
    protected ProductLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        // Mark system as installed
        SystemSetting::set('installed', true, 'boolean');

        // Create test organization
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'phone' => '123-456-7890',
            'address' => '123 Test St',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        // Create category and location
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

        // Create admin user with full permissions
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        // Create member with limited permissions
        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        // Create view-only user
        $this->viewOnlyUser = User::create([
            'name' => 'View Only User',
            'email' => 'viewer@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        // Create system roles
        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        // Admin role with full product permissions
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
                    'view_orders',
                    'create_orders',
                    'edit_orders',
                    'delete_orders',
                ],
            ]
        );

        // Member role with create/edit but no delete
        $memberRole = Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'description' => 'Basic member access',
                'is_system' => true,
                'permissions' => [
                    'view_products',
                    'create_products',
                    'edit_products',
                ],
            ]
        );

        // View-only role
        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'description' => 'View only access',
                'is_system' => true,
                'permissions' => ['view_products'],
            ]
        );

        // Assign roles to users
        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-' . uniqid(),
            'name' => 'Test Product',
            'description' => 'A test product description',
            'price' => 99.99,
            'purchase_price' => 50.00,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'max_stock' => 500,
            'is_active' => true,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ], $attributes));
    }

    // ==================== INDEX TESTS ====================

    public function test_admin_can_view_products_list(): void
    {
        $product = $this->createProduct();

        $response = $this->actingAs($this->admin)
            ->get(route('products.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('products')
            ->has('categories')
            ->has('locations')
        );
    }

    public function test_member_can_view_products_list(): void
    {
        $this->createProduct();

        $response = $this->actingAs($this->member)
            ->get(route('products.index'));

        $response->assertStatus(200);
    }

    public function test_products_list_can_be_searched(): void
    {
        $this->createProduct(['name' => 'Widget Alpha', 'sku' => 'WGT-001']);
        $this->createProduct(['name' => 'Gadget Beta', 'sku' => 'GDG-002']);

        $response = $this->actingAs($this->admin)
            ->get(route('products.index', ['search' => 'Widget']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->where('filters.search', 'Widget')
        );
    }

    public function test_products_list_can_be_filtered_by_category(): void
    {
        $otherCategory = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Category',
            'slug' => 'other-category',
            'is_active' => true,
        ]);

        $this->createProduct(['name' => 'Product A', 'category_id' => $this->category->id]);
        $this->createProduct(['name' => 'Product B', 'category_id' => $otherCategory->id]);

        $response = $this->actingAs($this->admin)
            ->get(route('products.index', ['category' => $this->category->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('filters.category', (string) $this->category->id)
        );
    }

    public function test_products_list_can_be_filtered_by_location(): void
    {
        $otherLocation = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse B',
            'code' => 'WH-B',
            'is_active' => true,
        ]);

        $this->createProduct(['name' => 'Product A', 'location_id' => $this->location->id]);
        $this->createProduct(['name' => 'Product B', 'location_id' => $otherLocation->id]);

        $response = $this->actingAs($this->admin)
            ->get(route('products.index', ['location' => $this->location->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('filters.location', (string) $this->location->id)
        );
    }

    public function test_products_list_can_filter_low_stock(): void
    {
        $this->createProduct(['name' => 'Normal Stock', 'stock' => 100, 'min_stock' => 10]);
        $this->createProduct(['name' => 'Low Stock', 'stock' => 5, 'min_stock' => 10]);

        $response = $this->actingAs($this->admin)
            ->get(route('products.index', ['low_stock' => true]));

        $response->assertStatus(200);
    }

    public function test_guest_cannot_view_products_list(): void
    {
        $response = $this->get(route('products.index'));

        $response->assertRedirect(route('login'));
    }

    // ==================== CREATE TESTS ====================

    public function test_admin_can_view_create_product_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('products.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Products/Create')
            ->has('categories')
            ->has('locations')
        );
    }

    public function test_member_can_view_create_product_form(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('products.create'));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_create_product_form(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('products.create'));

        $response->assertStatus(403);
    }

    // ==================== STORE TESTS ====================

    public function test_admin_can_create_product(): void
    {
        $productData = [
            'sku' => 'NEW-SKU-001',
            'name' => 'New Product',
            'description' => 'A brand new product',
            'price' => 149.99,
            'purchase_price' => 75.00,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 5,
            'max_stock' => 200,
            'is_active' => true,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('products.store'), $productData);

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('success', 'Product created successfully.');

        $this->assertDatabaseHas('products', [
            'sku' => 'NEW-SKU-001',
            'name' => 'New Product',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_member_can_create_product(): void
    {
        $productData = [
            'sku' => 'MEMBER-SKU-001',
            'name' => 'Member Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 25,
            'min_stock' => 5,
        ];

        $response = $this->actingAs($this->member)
            ->post(route('products.store'), $productData);

        $response->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('products', ['sku' => 'MEMBER-SKU-001']);
    }

    public function test_view_only_user_cannot_create_product(): void
    {
        $productData = [
            'sku' => 'UNAUTHORIZED-SKU',
            'name' => 'Unauthorized Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ];

        $response = $this->actingAs($this->viewOnlyUser)
            ->post(route('products.store'), $productData);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('products', ['sku' => 'UNAUTHORIZED-SKU']);
    }

    public function test_product_creation_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('products.store'), [
                'name' => '', // Required
                'sku' => '', // Required
                'price' => '', // Required
            ]);

        $response->assertSessionHasErrors(['name', 'sku', 'price', 'currency', 'stock', 'min_stock']);
    }

    public function test_product_creation_validates_unique_sku(): void
    {
        $this->createProduct(['sku' => 'EXISTING-SKU']);

        $response = $this->actingAs($this->admin)
            ->post(route('products.store'), [
                'sku' => 'EXISTING-SKU',
                'name' => 'Another Product',
                'price' => 99.99,
                'currency' => 'USD',
                'stock' => 10,
                'min_stock' => 1,
            ]);

        $response->assertSessionHasErrors(['sku']);
    }

    public function test_product_creation_validates_numeric_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('products.store'), [
                'sku' => 'TEST-SKU',
                'name' => 'Test Product',
                'price' => 'not-a-number',
                'currency' => 'USD',
                'stock' => 'invalid',
                'min_stock' => -5,
            ]);

        $response->assertSessionHasErrors(['price', 'stock', 'min_stock']);
    }

    public function test_product_creation_validates_category_exists(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('products.store'), [
                'sku' => 'TEST-SKU',
                'name' => 'Test Product',
                'price' => 99.99,
                'currency' => 'USD',
                'stock' => 10,
                'min_stock' => 1,
                'category_id' => 99999, // Non-existent
            ]);

        $response->assertSessionHasErrors(['category_id']);
    }

    // ==================== SHOW TESTS ====================

    public function test_admin_can_view_product(): void
    {
        $product = $this->createProduct();

        $response = $this->actingAs($this->admin)
            ->get(route('products.show', $product));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Products/Show')
            ->has('product')
        );
    }

    public function test_member_can_view_product(): void
    {
        $product = $this->createProduct();

        $response = $this->actingAs($this->member)
            ->get(route('products.show', $product));

        $response->assertStatus(200);
    }

    public function test_user_cannot_view_product_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-SKU',
            'name' => 'Other Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('products.show', $otherProduct));

        $response->assertStatus(403);
    }

    // ==================== EDIT TESTS ====================

    public function test_admin_can_view_edit_product_form(): void
    {
        $product = $this->createProduct();

        $response = $this->actingAs($this->admin)
            ->get(route('products.edit', $product));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Products/Edit')
            ->has('product')
            ->has('categories')
            ->has('locations')
        );
    }

    public function test_member_can_view_edit_product_form(): void
    {
        $product = $this->createProduct();

        $response = $this->actingAs($this->member)
            ->get(route('products.edit', $product));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_edit_product_form(): void
    {
        $product = $this->createProduct();

        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('products.edit', $product));

        $response->assertStatus(403);
    }

    public function test_user_cannot_edit_product_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-SKU',
            'name' => 'Other Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('products.edit', $otherProduct));

        $response->assertStatus(403);
    }

    // ==================== UPDATE TESTS ====================

    public function test_admin_can_update_product(): void
    {
        $product = $this->createProduct(['name' => 'Original Name']);

        $response = $this->actingAs($this->admin)
            ->put(route('products.update', $product), [
                'sku' => $product->sku,
                'name' => 'Updated Name',
                'price' => 199.99,
                'stock' => 75,
                'min_stock' => 15,
                'is_active' => true,
            ]);

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('success', 'Product updated successfully.');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Name',
            'price' => 199.99,
            'stock' => 75,
        ]);
    }

    public function test_member_can_update_product(): void
    {
        $product = $this->createProduct(['name' => 'Original Name']);

        $response = $this->actingAs($this->member)
            ->put(route('products.update', $product), [
                'sku' => $product->sku,
                'name' => 'Member Updated',
                'price' => 149.99,
                'stock' => 50,
                'min_stock' => 10,
            ]);

        $response->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Member Updated',
        ]);
    }

    public function test_view_only_user_cannot_update_product(): void
    {
        $product = $this->createProduct(['name' => 'Original Name']);

        $response = $this->actingAs($this->viewOnlyUser)
            ->put(route('products.update', $product), [
                'sku' => $product->sku,
                'name' => 'Should Not Update',
                'price' => 99.99,
                'stock' => 10,
                'min_stock' => 1,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Original Name',
        ]);
    }

    public function test_user_cannot_update_product_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-SKU',
            'name' => 'Other Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('products.update', $otherProduct), [
                'sku' => 'OTHER-SKU',
                'name' => 'Hacked Product',
                'price' => 0.01,
                'stock' => 10,
                'min_stock' => 1,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('products', [
            'id' => $otherProduct->id,
            'name' => 'Other Product',
        ]);
    }

    public function test_product_update_validates_unique_sku_except_self(): void
    {
        $product1 = $this->createProduct(['sku' => 'SKU-001']);
        $product2 = $this->createProduct(['sku' => 'SKU-002']);

        // Try to update product2 with product1's SKU
        $response = $this->actingAs($this->admin)
            ->put(route('products.update', $product2), [
                'sku' => 'SKU-001', // Already exists
                'name' => 'Updated Product',
                'price' => 99.99,
                'stock' => 10,
                'min_stock' => 1,
            ]);

        $response->assertSessionHasErrors(['sku']);
    }

    public function test_product_can_update_with_same_sku(): void
    {
        $product = $this->createProduct(['sku' => 'SAME-SKU']);

        $response = $this->actingAs($this->admin)
            ->put(route('products.update', $product), [
                'sku' => 'SAME-SKU', // Same SKU
                'name' => 'Updated Name',
                'price' => 149.99,
                'stock' => 50,
                'min_stock' => 10,
            ]);

        $response->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'sku' => 'SAME-SKU',
            'name' => 'Updated Name',
        ]);
    }

    // ==================== DELETE TESTS ====================

    public function test_admin_can_delete_product(): void
    {
        $product = $this->createProduct();

        $response = $this->actingAs($this->admin)
            ->delete(route('products.destroy', $product));

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('success', 'Product deleted successfully.');

        // Product should be soft deleted
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_member_cannot_delete_product(): void
    {
        $product = $this->createProduct();

        $response = $this->actingAs($this->member)
            ->delete(route('products.destroy', $product));

        $response->assertStatus(403);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'deleted_at' => null,
        ]);
    }

    public function test_view_only_user_cannot_delete_product(): void
    {
        $product = $this->createProduct();

        $response = $this->actingAs($this->viewOnlyUser)
            ->delete(route('products.destroy', $product));

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_product_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-SKU',
            'name' => 'Other Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('products.destroy', $otherProduct));

        $response->assertStatus(403);
        $this->assertDatabaseHas('products', [
            'id' => $otherProduct->id,
            'deleted_at' => null,
        ]);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_products_list_only_shows_organization_products(): void
    {
        // Create product for current organization
        $ownProduct = $this->createProduct(['name' => 'Own Product']);

        // Create product for different organization
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-SKU',
            'name' => 'Other Org Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('products.index'));

        $response->assertStatus(200);
        // The response should contain our product but not the other org's product
        $response->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('products.data', 1) // Only 1 product should be visible
        );
    }

    // ==================== MODEL ATTRIBUTE TESTS ====================

    public function test_product_low_stock_detection(): void
    {
        $lowStockProduct = $this->createProduct(['stock' => 5, 'min_stock' => 10]);
        $normalStockProduct = $this->createProduct(['stock' => 50, 'min_stock' => 10]);

        $this->assertTrue($lowStockProduct->isLowStock());
        $this->assertFalse($normalStockProduct->isLowStock());
    }

    public function test_product_out_of_stock_detection(): void
    {
        $outOfStockProduct = $this->createProduct(['stock' => 0]);
        $inStockProduct = $this->createProduct(['stock' => 10]);

        $this->assertTrue($outOfStockProduct->isOutOfStock());
        $this->assertFalse($inStockProduct->isOutOfStock());
    }

    public function test_product_profit_calculation(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'purchase_price' => 60.00,
            'stock' => 10,
        ]);

        $this->assertEquals(40.00, $product->profit);
        $this->assertEquals(40.00, $product->profit_margin); // 40%
        $this->assertEquals(400.00, $product->total_profit);
    }

    public function test_product_category_relationship(): void
    {
        $product = $this->createProduct(['category_id' => $this->category->id]);

        $this->assertNotNull($product->category);
        $this->assertEquals($this->category->id, $product->category->id);
        $this->assertEquals('Test Category', $product->category->name);
    }

    public function test_product_location_relationship(): void
    {
        $product = $this->createProduct(['location_id' => $this->location->id]);

        $this->assertNotNull($product->location);
        $this->assertEquals($this->location->id, $product->location->id);
        $this->assertEquals('Warehouse A', $product->location->name);
    }
}
