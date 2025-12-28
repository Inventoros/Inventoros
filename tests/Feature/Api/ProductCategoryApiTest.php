<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $viewOnlyUser;
    protected Organization $organization;

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

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
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
                'is_system' => true,
                'permissions' => [
                    'view_categories',
                    'manage_categories',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'is_system' => true,
                'permissions' => ['view_categories'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createCategory(array $attributes = []): ProductCategory
    {
        return ProductCategory::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'Test Category',
            'slug' => 'test-category-' . uniqid(),
            'is_active' => true,
        ], $attributes));
    }

    // ==================== INDEX TESTS ====================

    public function test_can_list_categories(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createCategory(['name' => 'Category 1', 'slug' => 'category-1']);
        $this->createCategory(['name' => 'Category 2', 'slug' => 'category-2']);

        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'is_active'],
                ],
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_can_search_categories(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createCategory(['name' => 'Electronics', 'slug' => 'electronics']);
        $this->createCategory(['name' => 'Clothing', 'slug' => 'clothing']);

        $response = $this->getJson('/api/v1/categories?search=Elect');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Electronics');
    }

    public function test_can_filter_active_categories(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createCategory(['name' => 'Active', 'slug' => 'active', 'is_active' => true]);
        $this->createCategory(['name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false]);

        $response = $this->getJson('/api/v1/categories?is_active=true');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_unauthenticated_cannot_list_categories(): void
    {
        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(401);
    }

    // ==================== STORE TESTS ====================

    public function test_can_create_category(): void
    {
        Sanctum::actingAs($this->admin);

        $categoryData = [
            'name' => 'New Category',
            'slug' => 'new-category',
            'description' => 'A new category description',
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/categories', $categoryData);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Category created successfully')
            ->assertJsonPath('data.name', 'New Category')
            ->assertJsonPath('data.slug', 'new-category');

        $this->assertDatabaseHas('product_categories', [
            'name' => 'New Category',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_create_category_validates_required_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/categories', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_create_category_validates_unique_slug(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createCategory(['slug' => 'existing-slug']);

        $response = $this->postJson('/api/v1/categories', [
            'name' => 'New Category',
            'slug' => 'existing-slug',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_create_category_auto_generates_slug(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/categories', [
            'name' => 'Auto Slug Category',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.slug', 'auto-slug-category');
    }

    // ==================== SHOW TESTS ====================

    public function test_can_view_category(): void
    {
        Sanctum::actingAs($this->admin);

        $category = $this->createCategory(['name' => 'View Test']);

        $response = $this->getJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.name', 'View Test');
    }

    public function test_cannot_view_category_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherCategory = ProductCategory::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Category',
            'slug' => 'other-category',
        ]);

        $response = $this->getJson("/api/v1/categories/{$otherCategory->id}");

        $response->assertStatus(404);
    }

    // ==================== UPDATE TESTS ====================

    public function test_can_update_category(): void
    {
        Sanctum::actingAs($this->admin);

        $category = $this->createCategory(['name' => 'Original']);

        $response = $this->putJson("/api/v1/categories/{$category->id}", [
            'name' => 'Updated',
            'description' => 'Updated description',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Category updated successfully')
            ->assertJsonPath('data.name', 'Updated');

        $this->assertDatabaseHas('product_categories', [
            'id' => $category->id,
            'name' => 'Updated',
        ]);
    }

    public function test_cannot_update_category_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherCategory = ProductCategory::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Category',
            'slug' => 'other-category',
        ]);

        $response = $this->putJson("/api/v1/categories/{$otherCategory->id}", [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(404);
    }

    // ==================== DELETE TESTS ====================

    public function test_can_delete_category(): void
    {
        Sanctum::actingAs($this->admin);

        $category = $this->createCategory();

        $response = $this->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Category deleted successfully');

        $this->assertDatabaseMissing('product_categories', ['id' => $category->id]);
    }

    public function test_cannot_delete_category_with_products(): void
    {
        Sanctum::actingAs($this->admin);

        $category = $this->createCategory();

        $location = \App\Models\Inventory\ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse',
            'code' => 'WH',
        ]);

        Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);

        $response = $this->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete category with associated products');
    }

    public function test_cannot_delete_category_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherCategory = ProductCategory::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Category',
            'slug' => 'other-category',
        ]);

        $response = $this->deleteJson("/api/v1/categories/{$otherCategory->id}");

        $response->assertStatus(404);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_categories_list_only_shows_organization_categories(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createCategory(['name' => 'Our Category', 'slug' => 'our-category']);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        ProductCategory::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Their Category',
            'slug' => 'their-category',
        ]);

        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Our Category');
    }

    // ==================== NESTED CATEGORIES TESTS ====================

    public function test_can_create_nested_category(): void
    {
        Sanctum::actingAs($this->admin);

        $parent = $this->createCategory(['name' => 'Parent', 'slug' => 'parent']);

        $response = $this->postJson('/api/v1/categories', [
            'name' => 'Child Category',
            'slug' => 'child-category',
            'parent_id' => $parent->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.parent_id', $parent->id);
    }

    public function test_category_includes_children(): void
    {
        Sanctum::actingAs($this->admin);

        $parent = $this->createCategory(['name' => 'Parent', 'slug' => 'parent']);
        $this->createCategory(['name' => 'Child', 'slug' => 'child', 'parent_id' => $parent->id]);

        $response = $this->getJson("/api/v1/categories/{$parent->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'children',
                ],
            ]);
    }
}
