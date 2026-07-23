<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $stockManager;

    protected User $viewOnlyUser;

    protected Organization $organization;

    protected Product $product;

    protected Product $product2;

    protected ProductCategory $category;

    protected ProductLocation $locationA;

    protected ProductLocation $locationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

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

        // Create category
        $this->category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Category',
            'slug' => 'test-category',
            'is_active' => true,
        ]);

        // Create locations
        $this->locationA = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse A',
            'code' => 'WH-A',
            'is_active' => true,
        ]);

        $this->locationB = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse B',
            'code' => 'WH-B',
            'is_active' => true,
        ]);

        // Create test products
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
            'location_id' => $this->locationA->id,
        ]);

        $this->product2 = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-PROD-002',
            'name' => 'Test Product 2',
            'price' => 49.99,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 5,
            'is_active' => true,
            'category_id' => $this->category->id,
            'location_id' => $this->locationA->id,
        ]);

        // Create admin user
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        // Create stock manager
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
                    'manage_locations',
                    'transfer_stock',
                ],
            ]
        );

        $stockManagerRole = Role::firstOrCreate(
            ['slug' => 'stock-manager'],
            [
                'name' => 'Stock Manager',
                'description' => 'Stock management access',
                'is_system' => true,
                'permissions' => [
                    'view_products',
                    'manage_stock',
                    'transfer_stock',
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
        $this->stockManager->roles()->syncWithoutDetaching([$stockManagerRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createTransfer(array $overrides = []): StockTransfer
    {
        $transfer = StockTransfer::create(array_merge([
            'organization_id' => $this->organization->id,
            'transfer_number' => StockTransfer::generateTransferNumber($this->organization->id),
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'transferred_by' => $this->admin->id,
            'status' => 'pending',
            'notes' => 'Test transfer',
        ], $overrides));

        StockTransferItem::create([
            'stock_transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
        ]);

        return $transfer;
    }

    // ==================== INDEX TESTS ====================

    public function test_admin_can_view_stock_transfers_list(): void
    {
        $this->createTransfer();

        $response = $this->actingAs($this->admin)
            ->get(route('stock-transfers.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('StockTransfers/Index')
            ->has('transfers')
            ->has('filters')
        );
    }

    public function test_stock_manager_can_view_stock_transfers_list(): void
    {
        $this->createTransfer();

        $response = $this->actingAs($this->stockManager)
            ->get(route('stock-transfers.index'));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_stock_transfers_list(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('stock-transfers.index'));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_view_stock_transfers_list(): void
    {
        $response = $this->get(route('stock-transfers.index'));

        $response->assertRedirect(route('login'));
    }

    // ==================== CREATE TESTS ====================

    public function test_admin_can_view_create_stock_transfer_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('stock-transfers.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('StockTransfers/Create')
            ->has('locations')
            ->has('products')
        );
    }

    public function test_view_only_user_cannot_view_create_stock_transfer_form(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('stock-transfers.create'));

        $response->assertStatus(403);
    }

    // ==================== STORE TESTS ====================

    public function test_admin_can_create_stock_transfer(): void
    {
        $data = [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'notes' => 'Moving stock to new warehouse',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'notes' => 'First batch',
                ],
                [
                    'product_id' => $this->product2->id,
                    'quantity' => 5,
                    'notes' => '',
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('stock-transfers.store'), $data);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('stock_transfers', [
            'organization_id' => $this->organization->id,
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'status' => 'pending',
        ]);

        $transfer = StockTransfer::latest()->first();
        $this->assertCount(2, $transfer->items);
        $this->assertStringStartsWith('ST-', $transfer->transfer_number);
    }

    public function test_stock_transfer_rejects_same_location(): void
    {
        $data = [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationA->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('stock-transfers.store'), $data);

        $response->assertSessionHasErrors(['to_location_id']);
    }

    public function test_stock_transfer_rejects_zero_quantity(): void
    {
        $data = [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 0,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('stock-transfers.store'), $data);

        $response->assertSessionHasErrors(['items.0.quantity']);
    }

    public function test_stock_transfer_rejects_negative_quantity(): void
    {
        $data = [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => -5,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('stock-transfers.store'), $data);

        $response->assertSessionHasErrors(['items.0.quantity']);
    }

    public function test_stock_transfer_requires_at_least_one_item(): void
    {
        $data = [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'items' => [],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('stock-transfers.store'), $data);

        $response->assertSessionHasErrors(['items']);
    }

    // ==================== SHOW TESTS ====================

    public function test_admin_can_view_stock_transfer(): void
    {
        $transfer = $this->createTransfer();

        $response = $this->actingAs($this->admin)
            ->get(route('stock-transfers.show', $transfer));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('StockTransfers/Show')
            ->has('transfer')
        );
    }

    public function test_user_cannot_view_stock_transfer_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherLocationA = ProductLocation::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other WH A',
            'code' => 'OWH-A',
            'is_active' => true,
        ]);

        $otherLocationB = ProductLocation::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other WH B',
            'code' => 'OWH-B',
            'is_active' => true,
        ]);

        $otherTransfer = StockTransfer::create([
            'organization_id' => $otherOrg->id,
            'transfer_number' => 'ST-20260312-0001',
            'from_location_id' => $otherLocationA->id,
            'to_location_id' => $otherLocationB->id,
            'transferred_by' => $this->admin->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('stock-transfers.show', $otherTransfer));

        $response->assertStatus(403);
    }

    // ==================== COMPLETE TRANSFER TESTS ====================

    public function test_completing_a_transfer_repoints_the_product_to_the_destination(): void
    {
        $transfer = $this->createTransfer(); // product at A, transfer A -> B

        $this->assertSame($this->locationA->id, $this->product->fresh()->location_id);

        $this->actingAs($this->admin)
            ->post(route('stock-transfers.complete', $transfer))
            ->assertRedirect(route('stock-transfers.show', $transfer));

        // The product now records the destination location, matching where the
        // goods physically moved.
        $this->assertSame($this->locationB->id, $this->product->fresh()->location_id);
    }

    public function test_completing_transfer_records_single_audit_adjustment_per_item(): void
    {
        $initialStock = $this->product->stock; // 100

        $transfer = $this->createTransfer(); // transfers 10 units

        $response = $this->actingAs($this->admin)
            ->post(route('stock-transfers.complete', $transfer));

        $response->assertRedirect(route('stock-transfers.show', $transfer));
        $response->assertSessionHas('success');

        $transfer->refresh();
        $this->assertEquals('completed', $transfer->status);
        $this->assertNotNull($transfer->completed_at);

        // Inventoros tracks stock globally on products.stock — there is no
        // per-location stock column. The transfer is paperwork; products.stock
        // must NOT change.
        $this->product->refresh();
        $this->assertEquals($initialStock, $this->product->stock);

        // Previously this wrote two cancelling adjustments (-qty and +qty) on
        // the same product row, which left the ledger reconciling to zero
        // while pretending stock had moved. The fix records one audit row per
        // item with adjustment_quantity = 0.
        $adjustments = StockAdjustment::where('reference_type', StockTransfer::class)
            ->where('reference_id', $transfer->id)
            ->get();

        $this->assertCount(1, $adjustments);
        $entry = $adjustments->first();
        $this->assertEquals('transfer', $entry->type);
        $this->assertEquals(0, $entry->adjustment_quantity);
        $this->assertEquals($initialStock, $entry->quantity_before);
        $this->assertEquals($initialStock, $entry->quantity_after);
        $this->assertStringContainsString('Warehouse A', $entry->reason);
        $this->assertStringContainsString('Warehouse B', $entry->reason);
    }

    public function test_completing_transfer_fails_when_source_stock_insufficient(): void
    {
        // Bypass ProductObserver — it fires NotificationService which loads
        // organization email settings via auth(), and the test acting-as
        // user hasn't been set yet at this point.
        Product::withoutEvents(fn () => $this->product->update(['stock' => 3]));

        $transfer = $this->createTransfer(); // wants to transfer 10

        $response = $this->actingAs($this->admin)
            ->post(route('stock-transfers.complete', $transfer));

        $response->assertRedirect(route('stock-transfers.show', $transfer));
        $response->assertSessionHas('error');

        $transfer->refresh();
        $this->assertEquals('pending', $transfer->status);
        $this->assertNull($transfer->completed_at);

        // No partial audit rows from the rolled-back transaction.
        $this->assertSame(
            0,
            StockAdjustment::where('reference_type', StockTransfer::class)
                ->where('reference_id', $transfer->id)
                ->count()
        );
    }

    public function test_cannot_complete_already_completed_transfer(): void
    {
        $transfer = $this->createTransfer(['status' => 'completed', 'completed_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->post(route('stock-transfers.complete', $transfer));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_cannot_complete_cancelled_transfer(): void
    {
        $transfer = $this->createTransfer(['status' => 'cancelled']);

        $response = $this->actingAs($this->admin)
            ->post(route('stock-transfers.complete', $transfer));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ==================== CANCEL TRANSFER TESTS ====================

    public function test_admin_can_cancel_pending_transfer(): void
    {
        $transfer = $this->createTransfer();
        $initialStock = $this->product->stock;

        $response = $this->actingAs($this->admin)
            ->post(route('stock-transfers.cancel', $transfer));

        $response->assertRedirect(route('stock-transfers.show', $transfer));
        $response->assertSessionHas('success');

        $transfer->refresh();
        $this->assertEquals('cancelled', $transfer->status);

        // Stock should not change on cancellation
        $this->product->refresh();
        $this->assertEquals($initialStock, $this->product->stock);
    }

    public function test_cannot_cancel_completed_transfer(): void
    {
        $transfer = $this->createTransfer(['status' => 'completed', 'completed_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->post(route('stock-transfers.cancel', $transfer));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ==================== TRANSFER NUMBER GENERATION ====================

    public function test_transfer_number_is_generated_correctly(): void
    {
        $number = StockTransfer::generateTransferNumber($this->organization->id);

        $this->assertStringStartsWith('ST-', $number);
        $this->assertMatchesRegularExpression('/^ST-\d{8}-\d{4}$/', $number);
    }

    public function test_transfer_numbers_increment(): void
    {
        $transfer1 = $this->createTransfer();
        $transfer2 = $this->createTransfer();

        $num1 = (int) substr($transfer1->transfer_number, -4);
        $num2 = (int) substr($transfer2->transfer_number, -4);

        $this->assertEquals($num1 + 1, $num2);
    }

    // ==================== ORGANIZATION ISOLATION ====================

    public function test_stock_transfers_list_only_shows_organization_transfers(): void
    {
        $this->createTransfer();

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherLocA = ProductLocation::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other A',
            'code' => 'OA',
            'is_active' => true,
        ]);

        $otherLocB = ProductLocation::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other B',
            'code' => 'OB',
            'is_active' => true,
        ]);

        StockTransfer::create([
            'organization_id' => $otherOrg->id,
            'transfer_number' => 'ST-20260312-9999',
            'from_location_id' => $otherLocA->id,
            'to_location_id' => $otherLocB->id,
            'transferred_by' => $this->admin->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('stock-transfers.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('StockTransfers/Index')
            ->has('transfers.data', 1)
        );
    }

    // ==================== MODEL RELATIONSHIP TESTS ====================

    public function test_stock_transfer_has_items(): void
    {
        $transfer = $this->createTransfer();
        $transfer->load('items');

        $this->assertCount(1, $transfer->items);
        $this->assertEquals($this->product->id, $transfer->items->first()->product_id);
    }

    public function test_stock_transfer_belongs_to_locations(): void
    {
        $transfer = $this->createTransfer();
        $transfer->load(['fromLocation', 'toLocation']);

        $this->assertEquals($this->locationA->id, $transfer->fromLocation->id);
        $this->assertEquals($this->locationB->id, $transfer->toLocation->id);
    }

    public function test_stock_transfer_belongs_to_user(): void
    {
        $transfer = $this->createTransfer();
        $transfer->load('transferredBy');

        $this->assertEquals($this->admin->id, $transfer->transferredBy->id);
    }
}
