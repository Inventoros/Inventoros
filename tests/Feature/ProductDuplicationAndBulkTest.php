<?php

declare(strict_types=1);

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

class ProductDuplicationAndBulkTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected User $viewOnlyUser;
    protected Organization $organization;
    protected ProductCategory $category;
    protected ProductCategory $otherCategory;
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
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);

        $this->otherCategory = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Clothing',
            'slug' => 'clothing',
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

        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $this->viewOnlyUser = User::create([
            'name' => 'View Only User',
            'email' => 'viewer@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
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
                    'export_data',
                ],
            ]
        );

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

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'description' => 'View only access',
                'is_system' => true,
                'permissions' => ['view_products'],
            ]
        );

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

    // ==================== DUPLICATION TESTS ====================

    public function test_admin_can_duplicate_product(): void
    {
        $product = $this->createProduct([
            'name' => 'Original Widget',
            'sku' => 'WIDGET-001',
            'price' => 29.99,
            'purchase_price' => 15.00,
            'stock' => 50,
            'description' => 'A great widget',
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('products.duplicate', $product));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'name' => 'Original Widget (Copy)',
            'price' => 29.99,
            'purchase_price' => 15.00,
            'stock' => 0,
            'description' => 'A great widget',
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'organization_id' => $this->organization->id,
        ]);

        // Verify new SKU is different
        $copy = Product::where('name', 'Original Widget (Copy)')->first();
        $this->assertNotNull($copy);
        $this->assertNotEquals($product->sku, $copy->sku);
        $this->assertEquals(0, $copy->stock);
    }

    public function test_duplicated_product_gets_unique_sku(): void
    {
        $product = $this->createProduct(['sku' => 'ORIG-SKU-001']);

        $this->actingAs($this->admin)
            ->post(route('products.duplicate', $product));

        $copy = Product::where('name', 'Test Product (Copy)')->first();
        $this->assertNotNull($copy);
        $this->assertNotEquals('ORIG-SKU-001', $copy->sku);
        // SKU should contain COPY indicator
        $this->assertStringContainsString('COPY', $copy->sku);
    }

    public function test_duplicated_product_has_zero_stock(): void
    {
        $product = $this->createProduct(['stock' => 500]);

        $this->actingAs($this->admin)
            ->post(route('products.duplicate', $product));

        $copy = Product::where('name', 'Test Product (Copy)')->first();
        $this->assertNotNull($copy);
        $this->assertEquals(0, $copy->stock);
    }

    public function test_user_cannot_duplicate_product_from_different_organization(): void
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
            ->post(route('products.duplicate', $otherProduct));

        $response->assertStatus(403);
    }

    // ==================== BULK DELETE TESTS ====================

    public function test_admin_can_bulk_delete_products(): void
    {
        $product1 = $this->createProduct(['name' => 'Product 1']);
        $product2 = $this->createProduct(['name' => 'Product 2']);
        $product3 = $this->createProduct(['name' => 'Product 3']);

        $response = $this->actingAs($this->admin)
            ->post(route('products.bulk.delete'), [
                'ids' => [$product1->id, $product2->id],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Products 1 and 2 should be soft-deleted
        $this->assertSoftDeleted('products', ['id' => $product1->id]);
        $this->assertSoftDeleted('products', ['id' => $product2->id]);

        // Product 3 should still exist
        $this->assertDatabaseHas('products', [
            'id' => $product3->id,
            'deleted_at' => null,
        ]);
    }

    public function test_bulk_delete_only_deletes_own_organization_products(): void
    {
        $ownProduct = $this->createProduct(['name' => 'Own Product']);

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
            ->post(route('products.bulk.delete'), [
                'ids' => [$ownProduct->id, $otherProduct->id],
            ]);

        $response->assertRedirect();

        // Own product should be soft-deleted
        $this->assertSoftDeleted('products', ['id' => $ownProduct->id]);

        // Other org product should NOT be deleted
        $this->assertDatabaseHas('products', [
            'id' => $otherProduct->id,
            'deleted_at' => null,
        ]);
    }

    // ==================== BULK CATEGORY UPDATE TESTS ====================

    public function test_admin_can_bulk_update_category(): void
    {
        $product1 = $this->createProduct(['name' => 'Product 1', 'category_id' => $this->category->id]);
        $product2 = $this->createProduct(['name' => 'Product 2', 'category_id' => $this->category->id]);

        $response = $this->actingAs($this->admin)
            ->post(route('products.bulk.update-category'), [
                'ids' => [$product1->id, $product2->id],
                'category_id' => $this->otherCategory->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'id' => $product1->id,
            'category_id' => $this->otherCategory->id,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product2->id,
            'category_id' => $this->otherCategory->id,
        ]);
    }

    // ==================== BULK PRICE UPDATE TESTS ====================

    public function test_admin_can_bulk_update_price_by_percentage(): void
    {
        $product1 = $this->createProduct(['name' => 'Product 1', 'price' => 100.00]);
        $product2 = $this->createProduct(['name' => 'Product 2', 'price' => 200.00]);

        $response = $this->actingAs($this->admin)
            ->post(route('products.bulk.update-price'), [
                'ids' => [$product1->id, $product2->id],
                'type' => 'percentage',
                'value' => 10, // +10%
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $product1->refresh();
        $product2->refresh();

        $this->assertEquals(110.00, (float) $product1->price);
        $this->assertEquals(220.00, (float) $product2->price);
    }

    public function test_admin_can_bulk_update_price_by_percentage_decrease(): void
    {
        $product1 = $this->createProduct(['name' => 'Product 1', 'price' => 100.00]);
        $product2 = $this->createProduct(['name' => 'Product 2', 'price' => 200.00]);

        $response = $this->actingAs($this->admin)
            ->post(route('products.bulk.update-price'), [
                'ids' => [$product1->id, $product2->id],
                'type' => 'percentage',
                'value' => -20, // -20%
            ]);

        $response->assertRedirect();

        $product1->refresh();
        $product2->refresh();

        $this->assertEquals(80.00, (float) $product1->price);
        $this->assertEquals(160.00, (float) $product2->price);
    }

    public function test_admin_can_bulk_update_price_by_fixed_amount(): void
    {
        $product1 = $this->createProduct(['name' => 'Product 1', 'price' => 100.00]);
        $product2 = $this->createProduct(['name' => 'Product 2', 'price' => 200.00]);

        $response = $this->actingAs($this->admin)
            ->post(route('products.bulk.update-price'), [
                'ids' => [$product1->id, $product2->id],
                'type' => 'fixed',
                'value' => 25.50, // +$25.50
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $product1->refresh();
        $product2->refresh();

        $this->assertEquals(125.50, (float) $product1->price);
        $this->assertEquals(225.50, (float) $product2->price);
    }

    public function test_bulk_price_update_does_not_go_below_zero(): void
    {
        $product = $this->createProduct(['name' => 'Cheap Product', 'price' => 5.00]);

        $response = $this->actingAs($this->admin)
            ->post(route('products.bulk.update-price'), [
                'ids' => [$product->id],
                'type' => 'fixed',
                'value' => -100, // Would make it negative
            ]);

        $response->assertRedirect();

        $product->refresh();
        $this->assertEquals(0.00, (float) $product->price);
    }

    // ==================== BULK EXPORT TESTS ====================

    public function test_admin_can_bulk_export_products(): void
    {
        $product1 = $this->createProduct(['name' => 'Export Product 1']);
        $product2 = $this->createProduct(['name' => 'Export Product 2']);

        $response = $this->actingAs($this->admin)
            ->post(route('products.bulk.export'), [
                'ids' => [$product1->id, $product2->id],
            ]);

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    // ==================== VALIDATION TESTS ====================

    public function test_bulk_delete_requires_ids(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('products.bulk.delete'), [
                'ids' => [],
            ]);

        $response->assertSessionHasErrors('ids');
    }

    public function test_bulk_update_category_requires_valid_category(): void
    {
        $product = $this->createProduct();

        $response = $this->actingAs($this->admin)
            ->post(route('products.bulk.update-category'), [
                'ids' => [$product->id],
                'category_id' => 99999,
            ]);

        $response->assertSessionHasErrors('category_id');
    }

    public function test_bulk_update_price_requires_valid_type(): void
    {
        $product = $this->createProduct();

        $response = $this->actingAs($this->admin)
            ->post(route('products.bulk.update-price'), [
                'ids' => [$product->id],
                'type' => 'invalid',
                'value' => 10,
            ]);

        $response->assertSessionHasErrors('type');
    }
}
