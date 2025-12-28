<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
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
        // Admin role with full order permissions
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
                    'view_orders',
                    'create_orders',
                    'edit_orders',
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
                'permissions' => ['view_orders'],
            ]
        );

        // Assign roles to users
        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createOrder(array $attributes = [], array $items = []): Order
    {
        $order = Order::create(array_merge([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-' . now()->format('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'source' => 'manual',
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@test.com',
            'customer_address' => '123 Customer St',
            'status' => 'pending',
            'subtotal' => 99.99,
            'tax' => 10.00,
            'shipping' => 5.00,
            'total' => 114.99,
            'currency' => 'USD',
            'order_date' => now(),
        ], $attributes));

        // Create order items
        if (empty($items)) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $this->product->id,
                'product_name' => $this->product->name,
                'sku' => $this->product->sku,
                'quantity' => 1,
                'unit_price' => 99.99,
                'subtotal' => 99.99,
                'tax' => 0,
                'total' => 99.99,
            ]);
        } else {
            foreach ($items as $item) {
                OrderItem::create(array_merge(['order_id' => $order->id], $item));
            }
        }

        return $order;
    }

    // ==================== INDEX TESTS ====================

    public function test_admin_can_view_orders_list(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->admin)
            ->get(route('orders.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Orders/Index')
            ->has('orders')
            ->has('statuses')
            ->has('sources')
        );
    }

    public function test_member_can_view_orders_list(): void
    {
        $this->createOrder();

        $response = $this->actingAs($this->member)
            ->get(route('orders.index'));

        $response->assertStatus(200);
    }

    public function test_orders_list_can_be_searched(): void
    {
        $this->createOrder(['customer_name' => 'John Doe']);
        $this->createOrder(['customer_name' => 'Jane Smith']);

        $response = $this->actingAs($this->admin)
            ->get(route('orders.index', ['search' => 'John']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Orders/Index')
            ->where('filters.search', 'John')
        );
    }

    public function test_orders_list_can_be_filtered_by_status(): void
    {
        $this->createOrder(['status' => 'pending']);
        $this->createOrder(['status' => 'processing']);
        $this->createOrder(['status' => 'shipped']);

        $response = $this->actingAs($this->admin)
            ->get(route('orders.index', ['status' => 'pending']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('filters.status', 'pending')
        );
    }

    public function test_orders_list_can_be_filtered_by_source(): void
    {
        $this->createOrder(['source' => 'manual']);
        $this->createOrder(['source' => 'ebay']);

        $response = $this->actingAs($this->admin)
            ->get(route('orders.index', ['source' => 'manual']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('filters.source', 'manual')
        );
    }

    public function test_guest_cannot_view_orders_list(): void
    {
        $response = $this->get(route('orders.index'));

        $response->assertRedirect(route('login'));
    }

    // ==================== CREATE TESTS ====================

    public function test_admin_can_view_create_order_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('orders.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Orders/Create')
            ->has('products')
        );
    }

    public function test_member_can_view_create_order_form(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('orders.create'));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_create_order_form(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('orders.create'));

        $response->assertStatus(403);
    }

    // ==================== STORE TESTS ====================

    public function test_admin_can_create_order(): void
    {
        $initialStock = $this->product->stock;

        $orderData = [
            'customer_name' => 'New Customer',
            'customer_email' => 'newcustomer@test.com',
            'customer_address' => '456 New St',
            'status' => 'pending',
            'order_date' => now()->format('Y-m-d'),
            'shipping' => 10.00,
            'tax' => 8.00,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 99.99,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('orders.store'), $orderData);

        $response->assertRedirect(route('orders.index'));
        $response->assertSessionHas('success', 'Order created successfully.');

        $this->assertDatabaseHas('orders', [
            'customer_name' => 'New Customer',
            'customer_email' => 'newcustomer@test.com',
            'organization_id' => $this->organization->id,
            'source' => 'manual',
        ]);

        // Check stock was decremented
        $this->product->refresh();
        $this->assertEquals($initialStock - 2, $this->product->stock);
    }

    public function test_member_can_create_order(): void
    {
        $orderData = [
            'customer_name' => 'Member Customer',
            'customer_email' => 'membercustomer@test.com',
            'status' => 'pending',
            'order_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 99.99,
                ],
            ],
        ];

        $response = $this->actingAs($this->member)
            ->post(route('orders.store'), $orderData);

        $response->assertRedirect(route('orders.index'));
        $this->assertDatabaseHas('orders', ['customer_name' => 'Member Customer']);
    }

    public function test_view_only_user_cannot_create_order(): void
    {
        $orderData = [
            'customer_name' => 'Unauthorized Customer',
            'status' => 'pending',
            'order_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 99.99,
                ],
            ],
        ];

        $response = $this->actingAs($this->viewOnlyUser)
            ->post(route('orders.store'), $orderData);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('orders', ['customer_name' => 'Unauthorized Customer']);
    }

    public function test_order_creation_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('orders.store'), [
                'customer_name' => '',
                'status' => '',
                'order_date' => '',
                'items' => [],
            ]);

        $response->assertSessionHasErrors(['customer_name', 'status', 'order_date', 'items']);
    }

    public function test_order_creation_validates_valid_status(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('orders.store'), [
                'customer_name' => 'Test Customer',
                'status' => 'invalid_status',
                'order_date' => now()->format('Y-m-d'),
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => 1,
                        'unit_price' => 99.99,
                    ],
                ],
            ]);

        $response->assertSessionHasErrors(['status']);
    }

    public function test_order_creation_validates_product_exists(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('orders.store'), [
                'customer_name' => 'Test Customer',
                'status' => 'pending',
                'order_date' => now()->format('Y-m-d'),
                'items' => [
                    [
                        'product_id' => 99999,
                        'quantity' => 1,
                        'unit_price' => 99.99,
                    ],
                ],
            ]);

        $response->assertSessionHasErrors(['items.0.product_id']);
    }

    public function test_order_creation_validates_quantity(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('orders.store'), [
                'customer_name' => 'Test Customer',
                'status' => 'pending',
                'order_date' => now()->format('Y-m-d'),
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => 0,
                        'unit_price' => 99.99,
                    ],
                ],
            ]);

        $response->assertSessionHasErrors(['items.0.quantity']);
    }

    public function test_order_calculates_totals_correctly(): void
    {
        $orderData = [
            'customer_name' => 'Totals Test Customer',
            'status' => 'pending',
            'order_date' => now()->format('Y-m-d'),
            'shipping' => 15.00,
            'tax' => 20.00,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 3,
                    'unit_price' => 50.00,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('orders.store'), $orderData);

        $response->assertRedirect(route('orders.index'));

        // subtotal = 3 * 50 = 150
        // total = 150 + 15 (shipping) + 20 (tax) = 185
        $this->assertDatabaseHas('orders', [
            'customer_name' => 'Totals Test Customer',
            'subtotal' => 150.00,
            'shipping' => 15.00,
            'tax' => 20.00,
            'total' => 185.00,
        ]);
    }

    // ==================== SHOW TESTS ====================

    public function test_admin_can_view_order(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->admin)
            ->get(route('orders.show', $order));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Orders/Show')
            ->has('order')
        );
    }

    public function test_member_can_view_order(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->member)
            ->get(route('orders.show', $order));

        $response->assertStatus(200);
    }

    public function test_user_cannot_view_order_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherOrder = Order::create([
            'organization_id' => $otherOrg->id,
            'order_number' => 'ORD-OTHER-001',
            'source' => 'manual',
            'customer_name' => 'Other Customer',
            'status' => 'pending',
            'subtotal' => 100,
            'total' => 100,
            'order_date' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('orders.show', $otherOrder));

        $response->assertStatus(403);
    }

    // ==================== EDIT TESTS ====================

    public function test_admin_can_view_edit_order_form(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->admin)
            ->get(route('orders.edit', $order));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Orders/Edit')
            ->has('order')
            ->has('products')
        );
    }

    public function test_member_can_view_edit_order_form(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->member)
            ->get(route('orders.edit', $order));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_edit_order_form(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('orders.edit', $order));

        $response->assertStatus(403);
    }

    public function test_user_cannot_edit_order_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherOrder = Order::create([
            'organization_id' => $otherOrg->id,
            'order_number' => 'ORD-OTHER-001',
            'source' => 'manual',
            'customer_name' => 'Other Customer',
            'status' => 'pending',
            'subtotal' => 100,
            'total' => 100,
            'order_date' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('orders.edit', $otherOrder));

        $response->assertStatus(403);
    }

    // ==================== UPDATE TESTS ====================

    public function test_admin_can_update_order(): void
    {
        $order = $this->createOrder(['customer_name' => 'Original Customer']);

        $response = $this->actingAs($this->admin)
            ->put(route('orders.update', $order), [
                'customer_name' => 'Updated Customer',
                'customer_email' => 'updated@test.com',
                'status' => 'processing',
                'order_date' => now()->format('Y-m-d'),
                'items' => [
                    [
                        'id' => $order->items->first()->id,
                        'product_id' => $this->product->id,
                        'quantity' => 1,
                        'unit_price' => 99.99,
                    ],
                ],
            ]);

        $response->assertRedirect(route('orders.index'));
        $response->assertSessionHas('success', 'Order updated successfully.');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_name' => 'Updated Customer',
            'status' => 'processing',
        ]);
    }

    public function test_member_can_update_order(): void
    {
        $order = $this->createOrder(['customer_name' => 'Original Customer']);

        $response = $this->actingAs($this->member)
            ->put(route('orders.update', $order), [
                'customer_name' => 'Member Updated',
                'status' => 'processing',
                'order_date' => now()->format('Y-m-d'),
                'items' => [
                    [
                        'id' => $order->items->first()->id,
                        'product_id' => $this->product->id,
                        'quantity' => 1,
                        'unit_price' => 99.99,
                    ],
                ],
            ]);

        $response->assertRedirect(route('orders.index'));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_name' => 'Member Updated',
        ]);
    }

    public function test_view_only_user_cannot_update_order(): void
    {
        $order = $this->createOrder(['customer_name' => 'Original Customer']);

        $response = $this->actingAs($this->viewOnlyUser)
            ->put(route('orders.update', $order), [
                'customer_name' => 'Should Not Update',
                'status' => 'processing',
                'order_date' => now()->format('Y-m-d'),
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => 1,
                        'unit_price' => 99.99,
                    ],
                ],
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_name' => 'Original Customer',
        ]);
    }

    public function test_user_cannot_update_order_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherOrder = Order::create([
            'organization_id' => $otherOrg->id,
            'order_number' => 'ORD-OTHER-001',
            'source' => 'manual',
            'customer_name' => 'Other Customer',
            'status' => 'pending',
            'subtotal' => 100,
            'total' => 100,
            'order_date' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('orders.update', $otherOrder), [
                'customer_name' => 'Hacked Customer',
                'status' => 'pending',
                'order_date' => now()->format('Y-m-d'),
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => 1,
                        'unit_price' => 99.99,
                    ],
                ],
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('orders', [
            'id' => $otherOrder->id,
            'customer_name' => 'Other Customer',
        ]);
    }

    public function test_order_update_adjusts_stock_on_quantity_change(): void
    {
        $order = $this->createOrder();
        $initialStock = $this->product->fresh()->stock;
        $orderItem = $order->items->first();

        // Increase quantity from 1 to 3
        $response = $this->actingAs($this->admin)
            ->put(route('orders.update', $order), [
                'customer_name' => 'Stock Test Customer',
                'status' => 'pending',
                'order_date' => now()->format('Y-m-d'),
                'items' => [
                    [
                        'id' => $orderItem->id,
                        'product_id' => $this->product->id,
                        'quantity' => 3,
                        'unit_price' => 99.99,
                    ],
                ],
            ]);

        $response->assertRedirect(route('orders.index'));

        // Stock should decrease by 2 (3 - 1)
        $this->product->refresh();
        $this->assertEquals($initialStock - 2, $this->product->stock);
    }

    public function test_order_update_sets_shipped_at_when_status_changes_to_shipped(): void
    {
        $order = $this->createOrder(['status' => 'processing']);

        $response = $this->actingAs($this->admin)
            ->put(route('orders.update', $order), [
                'customer_name' => $order->customer_name,
                'status' => 'shipped',
                'order_date' => now()->format('Y-m-d'),
                'items' => [
                    [
                        'id' => $order->items->first()->id,
                        'product_id' => $this->product->id,
                        'quantity' => 1,
                        'unit_price' => 99.99,
                    ],
                ],
            ]);

        $response->assertRedirect(route('orders.index'));

        $order->refresh();
        $this->assertNotNull($order->shipped_at);
    }

    public function test_order_update_sets_delivered_at_when_status_changes_to_delivered(): void
    {
        $order = $this->createOrder(['status' => 'shipped']);

        $response = $this->actingAs($this->admin)
            ->put(route('orders.update', $order), [
                'customer_name' => $order->customer_name,
                'status' => 'delivered',
                'order_date' => now()->format('Y-m-d'),
                'items' => [
                    [
                        'id' => $order->items->first()->id,
                        'product_id' => $this->product->id,
                        'quantity' => 1,
                        'unit_price' => 99.99,
                    ],
                ],
            ]);

        $response->assertRedirect(route('orders.index'));

        $order->refresh();
        $this->assertNotNull($order->delivered_at);
    }

    // ==================== DELETE TESTS ====================

    public function test_admin_can_delete_order(): void
    {
        $order = $this->createOrder();
        $initialStock = $this->product->fresh()->stock;

        $response = $this->actingAs($this->admin)
            ->delete(route('orders.destroy', $order));

        $response->assertRedirect(route('orders.index'));
        $response->assertSessionHas('success', 'Order deleted successfully.');

        // Order should be soft deleted
        $this->assertSoftDeleted('orders', ['id' => $order->id]);

        // Stock should be restored
        $this->product->refresh();
        $this->assertEquals($initialStock + 1, $this->product->stock);
    }

    public function test_member_cannot_delete_order(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->member)
            ->delete(route('orders.destroy', $order));

        $response->assertStatus(403);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'deleted_at' => null,
        ]);
    }

    public function test_view_only_user_cannot_delete_order(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->viewOnlyUser)
            ->delete(route('orders.destroy', $order));

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_order_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherOrder = Order::create([
            'organization_id' => $otherOrg->id,
            'order_number' => 'ORD-OTHER-001',
            'source' => 'manual',
            'customer_name' => 'Other Customer',
            'status' => 'pending',
            'subtotal' => 100,
            'total' => 100,
            'order_date' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('orders.destroy', $otherOrder));

        $response->assertStatus(403);
        $this->assertDatabaseHas('orders', [
            'id' => $otherOrder->id,
            'deleted_at' => null,
        ]);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_orders_list_only_shows_organization_orders(): void
    {
        // Create order for current organization
        $ownOrder = $this->createOrder(['customer_name' => 'Own Customer']);

        // Create order for different organization
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        Order::create([
            'organization_id' => $otherOrg->id,
            'order_number' => 'ORD-OTHER-001',
            'source' => 'manual',
            'customer_name' => 'Other Org Customer',
            'status' => 'pending',
            'subtotal' => 100,
            'total' => 100,
            'order_date' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('orders.index'));

        $response->assertStatus(200);
        // The response should contain our order but not the other org's order
        $response->assertInertia(fn ($page) => $page
            ->component('Orders/Index')
            ->has('orders.data', 1)
        );
    }

    // ==================== ORDER NUMBER GENERATION TESTS ====================

    public function test_order_number_is_auto_generated(): void
    {
        $orderData = [
            'customer_name' => 'Auto Number Customer',
            'status' => 'pending',
            'order_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 99.99,
                ],
            ],
        ];

        $this->actingAs($this->admin)
            ->post(route('orders.store'), $orderData);

        $order = Order::where('customer_name', 'Auto Number Customer')->first();
        $this->assertNotNull($order->order_number);
        $this->assertStringStartsWith('ORD-', $order->order_number);
    }

    // ==================== MODEL TESTS ====================

    public function test_order_items_relationship(): void
    {
        $order = $this->createOrder();

        $this->assertCount(1, $order->items);
        $this->assertEquals($this->product->id, $order->items->first()->product_id);
    }

    public function test_order_organization_relationship(): void
    {
        $order = $this->createOrder();
        $order->load('organization');

        $this->assertNotNull($order->organization);
        $this->assertEquals($this->organization->id, $order->organization->id);
    }
}
