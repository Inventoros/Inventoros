<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $stockManager;
    protected User $viewOnlyUser;
    protected Organization $organization;
    protected Product $product;
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

        // Create a test product
        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-PROD-001',
            'name' => 'Test Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'is_active' => true,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ]);

        // Create admin user with full permissions
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        // Create stock manager with stock permissions
        $this->stockManager = User::create([
            'name' => 'Stock Manager',
            'email' => 'stock@test.com',
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
        // Admin role with full permissions
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
                ],
            ]
        );

        // Stock manager role
        $stockManagerRole = Role::firstOrCreate(
            ['slug' => 'stock-manager'],
            [
                'name' => 'Stock Manager',
                'description' => 'Stock management access',
                'is_system' => true,
                'permissions' => [
                    'view_products',
                    'manage_stock',
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
        $this->stockManager->roles()->syncWithoutDetaching([$stockManagerRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createStockAdjustment(array $attributes = []): StockAdjustment
    {
        return StockAdjustment::create(array_merge([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'user_id' => $this->admin->id,
            'type' => 'manual',
            'quantity_before' => 100,
            'quantity_after' => 110,
            'adjustment_quantity' => 10,
            'reason' => 'Test adjustment',
            'notes' => 'Test notes',
        ], $attributes));
    }

    // ==================== INDEX TESTS ====================

    public function test_admin_can_view_stock_adjustments_list(): void
    {
        $adjustment = $this->createStockAdjustment();

        $response = $this->actingAs($this->admin)
            ->get(route('stock-adjustments.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('StockAdjustments/Index')
            ->has('adjustments')
            ->has('products')
            ->has('users')
            ->has('types')
        );
    }

    public function test_stock_manager_can_view_stock_adjustments_list(): void
    {
        $this->createStockAdjustment();

        $response = $this->actingAs($this->stockManager)
            ->get(route('stock-adjustments.index'));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_stock_adjustments_list(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('stock-adjustments.index'));

        $response->assertStatus(403);
    }

    public function test_stock_adjustments_can_be_searched(): void
    {
        $this->createStockAdjustment();

        $response = $this->actingAs($this->admin)
            ->get(route('stock-adjustments.index', ['search' => 'TEST-PROD']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('StockAdjustments/Index')
            ->where('filters.search', 'TEST-PROD')
        );
    }

    public function test_stock_adjustments_can_be_filtered_by_type(): void
    {
        $this->createStockAdjustment(['type' => 'manual']);
        $this->createStockAdjustment(['type' => 'damage']);

        $response = $this->actingAs($this->admin)
            ->get(route('stock-adjustments.index', ['type' => 'manual']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('filters.type', 'manual')
        );
    }

    public function test_stock_adjustments_can_be_filtered_by_product(): void
    {
        $this->createStockAdjustment();

        $response = $this->actingAs($this->admin)
            ->get(route('stock-adjustments.index', ['product_id' => $this->product->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('filters.product_id', (string) $this->product->id)
        );
    }

    public function test_stock_adjustments_can_be_filtered_by_user(): void
    {
        $this->createStockAdjustment(['user_id' => $this->admin->id]);

        $response = $this->actingAs($this->admin)
            ->get(route('stock-adjustments.index', ['user_id' => $this->admin->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('filters.user_id', (string) $this->admin->id)
        );
    }

    public function test_stock_adjustments_can_be_filtered_by_date_range(): void
    {
        $this->createStockAdjustment();

        $response = $this->actingAs($this->admin)
            ->get(route('stock-adjustments.index', [
                'date_from' => now()->subDay()->format('Y-m-d'),
                'date_to' => now()->addDay()->format('Y-m-d'),
            ]));

        $response->assertStatus(200);
    }

    public function test_guest_cannot_view_stock_adjustments_list(): void
    {
        $response = $this->get(route('stock-adjustments.index'));

        $response->assertRedirect(route('login'));
    }

    // ==================== CREATE TESTS ====================

    public function test_admin_can_view_create_stock_adjustment_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('stock-adjustments.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('StockAdjustments/Create')
            ->has('products')
            ->has('types')
        );
    }

    public function test_stock_manager_can_view_create_stock_adjustment_form(): void
    {
        $response = $this->actingAs($this->stockManager)
            ->get(route('stock-adjustments.create'));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_create_stock_adjustment_form(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('stock-adjustments.create'));

        $response->assertStatus(403);
    }

    // ==================== STORE TESTS ====================

    public function test_admin_can_create_positive_stock_adjustment(): void
    {
        $initialStock = $this->product->stock;

        $adjustmentData = [
            'product_id' => $this->product->id,
            'type' => 'manual',
            'adjustment_quantity' => 25,
            'reason' => 'Received new shipment',
            'notes' => 'From supplier ABC',
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), $adjustmentData);

        $response->assertRedirect(route('stock-adjustments.index'));
        $response->assertSessionHas('success', 'Stock adjustment created successfully.');

        // Check adjustment was created
        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->product->id,
            'type' => 'manual',
            'adjustment_quantity' => 25,
            'quantity_before' => $initialStock,
            'quantity_after' => $initialStock + 25,
            'reason' => 'Received new shipment',
        ]);

        // Check stock was updated
        $this->product->refresh();
        $this->assertEquals($initialStock + 25, $this->product->stock);
    }

    public function test_admin_can_create_negative_stock_adjustment(): void
    {
        $initialStock = $this->product->stock;

        $adjustmentData = [
            'product_id' => $this->product->id,
            'type' => 'damage',
            'adjustment_quantity' => -15,
            'reason' => 'Items damaged in warehouse',
            'notes' => 'Water damage from leak',
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), $adjustmentData);

        $response->assertRedirect(route('stock-adjustments.index'));

        // Check adjustment was created
        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->product->id,
            'type' => 'damage',
            'adjustment_quantity' => -15,
            'quantity_before' => $initialStock,
            'quantity_after' => $initialStock - 15,
        ]);

        // Check stock was updated
        $this->product->refresh();
        $this->assertEquals($initialStock - 15, $this->product->stock);
    }

    public function test_stock_manager_can_create_stock_adjustment(): void
    {
        $initialStock = $this->product->stock;

        $adjustmentData = [
            'product_id' => $this->product->id,
            'type' => 'recount',
            'adjustment_quantity' => 10,
            'reason' => 'Physical inventory count',
        ];

        $response = $this->actingAs($this->stockManager)
            ->post(route('stock-adjustments.store'), $adjustmentData);

        $response->assertRedirect(route('stock-adjustments.index'));

        // Check stock was updated
        $this->product->refresh();
        $this->assertEquals($initialStock + 10, $this->product->stock);
    }

    public function test_view_only_user_cannot_create_stock_adjustment(): void
    {
        $initialStock = $this->product->stock;

        $adjustmentData = [
            'product_id' => $this->product->id,
            'type' => 'manual',
            'adjustment_quantity' => 10,
            'reason' => 'Unauthorized adjustment',
        ];

        $response = $this->actingAs($this->viewOnlyUser)
            ->post(route('stock-adjustments.store'), $adjustmentData);

        $response->assertStatus(403);

        // Check stock was not changed
        $this->product->refresh();
        $this->assertEquals($initialStock, $this->product->stock);
    }

    public function test_stock_adjustment_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), [
                'product_id' => '',
                'type' => '',
                'adjustment_quantity' => '',
                'reason' => '',
            ]);

        $response->assertSessionHasErrors(['product_id', 'type', 'adjustment_quantity', 'reason']);
    }

    public function test_stock_adjustment_validates_product_exists(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), [
                'product_id' => 99999,
                'type' => 'manual',
                'adjustment_quantity' => 10,
                'reason' => 'Test reason',
            ]);

        $response->assertSessionHasErrors(['product_id']);
    }

    public function test_stock_adjustment_validates_valid_type(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $this->product->id,
                'type' => 'invalid_type',
                'adjustment_quantity' => 10,
                'reason' => 'Test reason',
            ]);

        $response->assertSessionHasErrors(['type']);
    }

    public function test_stock_adjustment_validates_non_zero_quantity(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $this->product->id,
                'type' => 'manual',
                'adjustment_quantity' => 0,
                'reason' => 'Test reason',
            ]);

        $response->assertSessionHasErrors(['adjustment_quantity']);
    }

    public function test_stock_adjustment_validates_integer_quantity(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $this->product->id,
                'type' => 'manual',
                'adjustment_quantity' => 'not-a-number',
                'reason' => 'Test reason',
            ]);

        $response->assertSessionHasErrors(['adjustment_quantity']);
    }

    public function test_stock_adjustment_cannot_adjust_other_organization_product(): void
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
            'stock' => 50,
            'min_stock' => 5,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $otherProduct->id,
                'type' => 'manual',
                'adjustment_quantity' => 10,
                'reason' => 'Trying to adjust other org product',
            ]);

        $response->assertStatus(404);

        // Stock should not change
        $otherProduct->refresh();
        $this->assertEquals(50, $otherProduct->stock);
    }

    public function test_stock_adjustment_records_user(): void
    {
        $adjustmentData = [
            'product_id' => $this->product->id,
            'type' => 'manual',
            'adjustment_quantity' => 10,
            'reason' => 'User tracking test',
        ];

        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), $adjustmentData);

        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->product->id,
            'user_id' => $this->admin->id,
            'reason' => 'User tracking test',
        ]);
    }

    // ==================== SHOW TESTS ====================

    public function test_admin_can_view_stock_adjustment(): void
    {
        $adjustment = $this->createStockAdjustment();

        $response = $this->actingAs($this->admin)
            ->get(route('stock-adjustments.show', $adjustment));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('StockAdjustments/Show')
            ->has('adjustment')
        );
    }

    public function test_stock_manager_can_view_stock_adjustment(): void
    {
        $adjustment = $this->createStockAdjustment();

        $response = $this->actingAs($this->stockManager)
            ->get(route('stock-adjustments.show', $adjustment));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_stock_adjustment(): void
    {
        $adjustment = $this->createStockAdjustment();

        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('stock-adjustments.show', $adjustment));

        $response->assertStatus(403);
    }

    public function test_user_cannot_view_stock_adjustment_from_different_organization(): void
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
            'stock' => 50,
            'min_stock' => 5,
        ]);

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'otheruser@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $otherOrg->id,
            'role' => 'admin',
        ]);

        $otherAdjustment = StockAdjustment::create([
            'organization_id' => $otherOrg->id,
            'product_id' => $otherProduct->id,
            'user_id' => $otherUser->id,
            'type' => 'manual',
            'quantity_before' => 50,
            'quantity_after' => 60,
            'adjustment_quantity' => 10,
            'reason' => 'Other org adjustment',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('stock-adjustments.show', $otherAdjustment));

        $response->assertStatus(403);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_stock_adjustments_list_only_shows_organization_adjustments(): void
    {
        // Create adjustment for current organization
        $ownAdjustment = $this->createStockAdjustment();

        // Create adjustment for different organization
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
            'stock' => 50,
            'min_stock' => 5,
        ]);

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'otheruser@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $otherOrg->id,
            'role' => 'admin',
        ]);

        StockAdjustment::create([
            'organization_id' => $otherOrg->id,
            'product_id' => $otherProduct->id,
            'user_id' => $otherUser->id,
            'type' => 'manual',
            'quantity_before' => 50,
            'quantity_after' => 60,
            'adjustment_quantity' => 10,
            'reason' => 'Other org adjustment',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('stock-adjustments.index'));

        $response->assertStatus(200);
        // The response should contain our adjustment but not the other org's adjustment
        $response->assertInertia(fn ($page) => $page
            ->component('StockAdjustments/Index')
            ->has('adjustments.data', 1)
        );
    }

    // ==================== MODEL TESTS ====================

    public function test_stock_adjustment_product_relationship(): void
    {
        $adjustment = $this->createStockAdjustment();
        $adjustment->load('product');

        $this->assertNotNull($adjustment->product);
        $this->assertEquals($this->product->id, $adjustment->product->id);
    }

    public function test_stock_adjustment_user_relationship(): void
    {
        $adjustment = $this->createStockAdjustment(['user_id' => $this->admin->id]);
        $adjustment->load('user');

        $this->assertNotNull($adjustment->user);
        $this->assertEquals($this->admin->id, $adjustment->user->id);
    }

    public function test_stock_adjustment_organization_relationship(): void
    {
        $adjustment = $this->createStockAdjustment();
        $adjustment->load('organization');

        $this->assertNotNull($adjustment->organization);
        $this->assertEquals($this->organization->id, $adjustment->organization->id);
    }

    // ==================== DIFFERENT ADJUSTMENT TYPES ====================

    public function test_stock_adjustment_type_recount(): void
    {
        $initialStock = $this->product->stock;

        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $this->product->id,
                'type' => 'recount',
                'adjustment_quantity' => -5,
                'reason' => 'Physical count shows less',
            ]);

        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->product->id,
            'type' => 'recount',
        ]);

        $this->product->refresh();
        $this->assertEquals($initialStock - 5, $this->product->stock);
    }

    public function test_stock_adjustment_type_damage(): void
    {
        $initialStock = $this->product->stock;

        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $this->product->id,
                'type' => 'damage',
                'adjustment_quantity' => -3,
                'reason' => 'Items damaged during handling',
            ]);

        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->product->id,
            'type' => 'damage',
        ]);

        $this->product->refresh();
        $this->assertEquals($initialStock - 3, $this->product->stock);
    }

    public function test_stock_adjustment_type_loss(): void
    {
        $initialStock = $this->product->stock;

        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $this->product->id,
                'type' => 'loss',
                'adjustment_quantity' => -2,
                'reason' => 'Items missing from inventory',
            ]);

        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->product->id,
            'type' => 'loss',
        ]);

        $this->product->refresh();
        $this->assertEquals($initialStock - 2, $this->product->stock);
    }

    public function test_stock_adjustment_type_return(): void
    {
        $initialStock = $this->product->stock;

        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $this->product->id,
                'type' => 'return',
                'adjustment_quantity' => 5,
                'reason' => 'Customer returned items',
            ]);

        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->product->id,
            'type' => 'return',
        ]);

        $this->product->refresh();
        $this->assertEquals($initialStock + 5, $this->product->stock);
    }

    public function test_stock_adjustment_type_correction(): void
    {
        $initialStock = $this->product->stock;

        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), [
                'product_id' => $this->product->id,
                'type' => 'correction',
                'adjustment_quantity' => 8,
                'reason' => 'Correcting previous error',
            ]);

        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->product->id,
            'type' => 'correction',
        ]);

        $this->product->refresh();
        $this->assertEquals($initialStock + 8, $this->product->stock);
    }
}
