<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\ReturnOrder;
use App\Models\Order\ReturnOrderItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected User $viewOnlyUser;
    protected Organization $organization;
    protected Product $product;
    protected Product $product2;
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
            'location_id' => $this->location->id,
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
            'role' => 'viewer',
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
                    'view_orders',
                    'create_orders',
                    'edit_orders',
                    'delete_orders',
                    'manage_returns',
                    'manage_stock',
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
                    'view_orders',
                    'create_orders',
                    'edit_orders',
                    'manage_returns',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'description' => 'View only access',
                'is_system' => true,
                'permissions' => ['view_orders'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createOrder(array $attributes = [], array $items = []): Order
    {
        $order = Order::create(array_merge([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-' . now()->format('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'source' => 'manual',
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@test.com',
            'customer_address' => '123 Customer St',
            'status' => 'delivered',
            'subtotal' => 199.97,
            'tax' => 10.00,
            'shipping' => 5.00,
            'total' => 214.97,
            'currency' => 'USD',
            'order_date' => now(),
        ], $attributes));

        if (empty($items)) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $this->product->id,
                'product_name' => $this->product->name,
                'sku' => $this->product->sku,
                'quantity' => 3,
                'unit_price' => 99.99,
                'subtotal' => 299.97,
                'tax' => 0,
                'total' => 299.97,
            ]);
        } else {
            foreach ($items as $item) {
                OrderItem::create(array_merge(['order_id' => $order->id], $item));
            }
        }

        return $order->fresh(['items']);
    }

    protected function createReturnOrder(Order $order, array $attributes = [], array $items = []): ReturnOrder
    {
        $returnOrder = ReturnOrder::create(array_merge([
            'organization_id' => $this->organization->id,
            'order_id' => $order->id,
            'return_number' => ReturnOrder::generateReturnNumber($this->organization->id),
            'type' => 'return',
            'status' => 'pending',
            'reason' => 'Defective product',
            'notes' => 'Product arrived damaged',
            'refund_amount' => 99.99,
        ], $attributes));

        if (empty($items)) {
            $orderItem = $order->items->first();
            ReturnOrderItem::create([
                'return_order_id' => $returnOrder->id,
                'order_item_id' => $orderItem->id,
                'product_id' => $orderItem->product_id,
                'quantity' => 1,
                'condition' => 'damaged',
                'restock' => false,
            ]);
        } else {
            foreach ($items as $item) {
                // Always set the return_order_id
                $item['return_order_id'] = $returnOrder->id;
                ReturnOrderItem::create($item);
            }
        }

        return $returnOrder->fresh(['items']);
    }

    // ==================== INDEX TESTS ====================

    public function test_admin_can_view_returns_list(): void
    {
        $order = $this->createOrder();
        $this->createReturnOrder($order);

        $response = $this->actingAs($this->admin)
            ->get(route('returns.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Returns/Index')
            ->has('returns')
        );
    }

    public function test_member_can_view_returns_list(): void
    {
        $order = $this->createOrder();
        $this->createReturnOrder($order);

        $response = $this->actingAs($this->member)
            ->get(route('returns.index'));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_returns_list(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('returns.index'));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_view_returns_list(): void
    {
        $response = $this->get(route('returns.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_returns_list_filters_by_status(): void
    {
        $order = $this->createOrder();
        $this->createReturnOrder($order, ['status' => 'pending']);
        $this->createReturnOrder($order, ['status' => 'approved']);

        $response = $this->actingAs($this->admin)
            ->get(route('returns.index', ['status' => 'pending']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('filters.status', 'pending')
        );
    }

    public function test_returns_list_filters_by_type(): void
    {
        $order = $this->createOrder();
        $this->createReturnOrder($order, ['type' => 'return']);
        $this->createReturnOrder($order, ['type' => 'exchange']);

        $response = $this->actingAs($this->admin)
            ->get(route('returns.index', ['type' => 'return']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('filters.type', 'return')
        );
    }

    public function test_returns_list_only_shows_organization_returns(): void
    {
        $order = $this->createOrder();
        $this->createReturnOrder($order);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherOrder = Order::create([
            'organization_id' => $otherOrg->id,
            'order_number' => 'ORD-OTHER-001',
            'source' => 'manual',
            'customer_name' => 'Other Customer',
            'status' => 'delivered',
            'subtotal' => 100,
            'total' => 100,
            'order_date' => now(),
        ]);

        ReturnOrder::create([
            'organization_id' => $otherOrg->id,
            'order_id' => $otherOrder->id,
            'return_number' => 'RMA-OTHER-0001',
            'type' => 'return',
            'status' => 'pending',
            'reason' => 'Test',
            'refund_amount' => 50,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('returns.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('returns.data', 1)
        );
    }

    // ==================== CREATE TESTS ====================

    public function test_admin_can_view_create_return_form(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->admin)
            ->get(route('returns.create', ['order_id' => $order->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Returns/Create')
            ->has('order')
        );
    }

    // ==================== STORE TESTS ====================

    public function test_admin_can_create_return_from_order(): void
    {
        $order = $this->createOrder();
        $orderItem = $order->items->first();

        $returnData = [
            'order_id' => $order->id,
            'type' => 'return',
            'reason' => 'Defective product',
            'notes' => 'Product was damaged on arrival',
            'items' => [
                [
                    'order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'quantity' => 2,
                    'condition' => 'damaged',
                    'restock' => false,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('returns.store'), $returnData);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('return_orders', [
            'order_id' => $order->id,
            'type' => 'return',
            'status' => 'pending',
            'reason' => 'Defective product',
            'organization_id' => $this->organization->id,
        ]);

        $returnOrder = ReturnOrder::where('order_id', $order->id)->first();
        $this->assertNotNull($returnOrder);
        $this->assertStringStartsWith('RMA-', $returnOrder->return_number);
        $this->assertEquals(2 * $orderItem->unit_price, $returnOrder->refund_amount);

        $this->assertDatabaseHas('return_order_items', [
            'return_order_id' => $returnOrder->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $orderItem->product_id,
            'quantity' => 2,
            'condition' => 'damaged',
            'restock' => false,
        ]);
    }

    public function test_cannot_return_more_than_ordered_quantity(): void
    {
        $order = $this->createOrder();
        $orderItem = $order->items->first();

        $returnData = [
            'order_id' => $order->id,
            'type' => 'return',
            'reason' => 'Wrong item',
            'items' => [
                [
                    'order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'quantity' => $orderItem->quantity + 1, // More than ordered
                    'condition' => 'new',
                    'restock' => true,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('returns.store'), $returnData);

        $response->assertSessionHasErrors(['items.0.quantity']);
    }

    public function test_cannot_return_more_than_remaining_quantity_after_previous_returns(): void
    {
        $order = $this->createOrder();
        $orderItem = $order->items->first();

        // Create a first return for 2 items
        $this->createReturnOrder($order, [], [
            [
                'return_order_id' => null, // will be set by createReturnOrder
                'order_item_id' => $orderItem->id,
                'product_id' => $orderItem->product_id,
                'quantity' => 2,
                'condition' => 'new',
                'restock' => true,
            ],
        ]);

        // Try to return 2 more (ordered 3, already returning 2, so only 1 left)
        $returnData = [
            'order_id' => $order->id,
            'type' => 'return',
            'reason' => 'Wrong item',
            'items' => [
                [
                    'order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'quantity' => 2,
                    'condition' => 'new',
                    'restock' => true,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('returns.store'), $returnData);

        $response->assertSessionHasErrors(['items.0.quantity']);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('returns.store'), [
                'order_id' => '',
                'type' => '',
                'reason' => '',
                'items' => [],
            ]);

        $response->assertSessionHasErrors(['order_id', 'type', 'reason', 'items']);
    }

    public function test_store_validates_valid_type(): void
    {
        $order = $this->createOrder();
        $orderItem = $order->items->first();

        $response = $this->actingAs($this->admin)
            ->post(route('returns.store'), [
                'order_id' => $order->id,
                'type' => 'invalid_type',
                'reason' => 'Test',
                'items' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'product_id' => $orderItem->product_id,
                        'quantity' => 1,
                        'condition' => 'new',
                        'restock' => true,
                    ],
                ],
            ]);

        $response->assertSessionHasErrors(['type']);
    }

    public function test_return_number_auto_generated(): void
    {
        $order = $this->createOrder();
        $orderItem = $order->items->first();

        $this->actingAs($this->admin)
            ->post(route('returns.store'), [
                'order_id' => $order->id,
                'type' => 'return',
                'reason' => 'Defective',
                'items' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'product_id' => $orderItem->product_id,
                        'quantity' => 1,
                        'condition' => 'new',
                        'restock' => true,
                    ],
                ],
            ]);

        $returnOrder = ReturnOrder::first();
        $this->assertNotNull($returnOrder);
        $this->assertMatchesRegularExpression('/^RMA-\d{8}-\d{4}$/', $returnOrder->return_number);
    }

    // ==================== SHOW TESTS ====================

    public function test_admin_can_view_return_details(): void
    {
        $order = $this->createOrder();
        $returnOrder = $this->createReturnOrder($order);

        $response = $this->actingAs($this->admin)
            ->get(route('returns.show', $returnOrder));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Returns/Show')
            ->has('returnOrder')
        );
    }

    public function test_user_cannot_view_return_from_different_organization(): void
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
            'status' => 'delivered',
            'subtotal' => 100,
            'total' => 100,
            'order_date' => now(),
        ]);

        $otherReturn = ReturnOrder::create([
            'organization_id' => $otherOrg->id,
            'order_id' => $otherOrder->id,
            'return_number' => 'RMA-OTHER-0001',
            'type' => 'return',
            'status' => 'pending',
            'reason' => 'Test',
            'refund_amount' => 50,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('returns.show', $otherReturn));

        $response->assertStatus(403);
    }

    // ==================== APPROVE FLOW ====================

    public function test_admin_can_approve_return(): void
    {
        $order = $this->createOrder();
        $returnOrder = $this->createReturnOrder($order);

        $response = $this->actingAs($this->admin)
            ->post(route('returns.approve', $returnOrder));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $returnOrder->refresh();
        $this->assertEquals('approved', $returnOrder->status);
        $this->assertEquals($this->admin->id, $returnOrder->processed_by);
    }

    public function test_cannot_approve_non_pending_return(): void
    {
        $order = $this->createOrder();
        $returnOrder = $this->createReturnOrder($order, ['status' => 'approved']);

        $response = $this->actingAs($this->admin)
            ->post(route('returns.approve', $returnOrder));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ==================== RECEIVE + RESTOCK TESTS ====================

    public function test_receive_restocks_items_marked_for_restock(): void
    {
        $order = $this->createOrder();
        $orderItem = $order->items->first();
        $initialStock = $this->product->fresh()->stock;

        $returnOrder = $this->createReturnOrder($order, ['status' => 'approved'], [
            [
                'order_item_id' => $orderItem->id,
                'product_id' => $this->product->id,
                'quantity' => 2,
                'condition' => 'new',
                'restock' => true,
            ],
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('returns.receive', $returnOrder));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $returnOrder->refresh();
        $this->assertEquals('received', $returnOrder->status);

        // Stock should be increased by 2
        $this->product->refresh();
        $this->assertEquals($initialStock + 2, $this->product->stock);

        // StockAdjustment should be created
        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->product->id,
            'type' => 'return',
            'adjustment_quantity' => 2,
        ]);
    }

    public function test_receive_does_not_restock_items_not_marked_for_restock(): void
    {
        $order = $this->createOrder();
        $orderItem = $order->items->first();
        $initialStock = $this->product->fresh()->stock;

        $returnOrder = $this->createReturnOrder($order, ['status' => 'approved'], [
            [
                'order_item_id' => $orderItem->id,
                'product_id' => $this->product->id,
                'quantity' => 2,
                'condition' => 'damaged',
                'restock' => false,
            ],
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('returns.receive', $returnOrder));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Stock should remain the same
        $this->product->refresh();
        $this->assertEquals($initialStock, $this->product->stock);
    }

    public function test_cannot_receive_non_approved_return(): void
    {
        $order = $this->createOrder();
        $returnOrder = $this->createReturnOrder($order, ['status' => 'pending']);

        $response = $this->actingAs($this->admin)
            ->post(route('returns.receive', $returnOrder));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ==================== COMPLETE TESTS ====================

    public function test_admin_can_complete_return(): void
    {
        $order = $this->createOrder();
        $returnOrder = $this->createReturnOrder($order, ['status' => 'received']);

        $response = $this->actingAs($this->admin)
            ->post(route('returns.complete', $returnOrder));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $returnOrder->refresh();
        $this->assertEquals('completed', $returnOrder->status);
        $this->assertNotNull($returnOrder->completed_at);
    }

    public function test_cannot_complete_non_received_return(): void
    {
        $order = $this->createOrder();
        $returnOrder = $this->createReturnOrder($order, ['status' => 'approved']);

        $response = $this->actingAs($this->admin)
            ->post(route('returns.complete', $returnOrder));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ==================== REJECT TESTS ====================

    public function test_admin_can_reject_return(): void
    {
        $order = $this->createOrder();
        $returnOrder = $this->createReturnOrder($order);

        $response = $this->actingAs($this->admin)
            ->post(route('returns.reject', $returnOrder), [
                'notes' => 'Return not accepted - outside return window',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $returnOrder->refresh();
        $this->assertEquals('rejected', $returnOrder->status);
        $this->assertStringContainsString('outside return window', $returnOrder->notes);
    }

    public function test_cannot_reject_non_pending_return(): void
    {
        $order = $this->createOrder();
        $returnOrder = $this->createReturnOrder($order, ['status' => 'approved']);

        $response = $this->actingAs($this->admin)
            ->post(route('returns.reject', $returnOrder), [
                'notes' => 'Trying to reject',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ==================== MODEL TESTS ====================

    public function test_return_order_belongs_to_order(): void
    {
        $order = $this->createOrder();
        $returnOrder = $this->createReturnOrder($order);

        $this->assertNotNull($returnOrder->order);
        $this->assertEquals($order->id, $returnOrder->order->id);
    }

    public function test_return_order_has_items(): void
    {
        $order = $this->createOrder();
        $returnOrder = $this->createReturnOrder($order);

        $this->assertCount(1, $returnOrder->items);
    }

    public function test_return_number_generation_scoped_by_organization(): void
    {
        $number1 = ReturnOrder::generateReturnNumber($this->organization->id);
        $this->assertMatchesRegularExpression('/^RMA-\d{8}-0001$/', $number1);

        // Create a return with this number
        $order = $this->createOrder();
        ReturnOrder::create([
            'organization_id' => $this->organization->id,
            'order_id' => $order->id,
            'return_number' => $number1,
            'type' => 'return',
            'status' => 'pending',
            'reason' => 'Test',
            'refund_amount' => 0,
        ]);

        $number2 = ReturnOrder::generateReturnNumber($this->organization->id);
        $this->assertMatchesRegularExpression('/^RMA-\d{8}-0002$/', $number2);
    }

    // ==================== EXCHANGE TYPE TESTS ====================

    public function test_can_create_exchange_return(): void
    {
        $order = $this->createOrder();
        $orderItem = $order->items->first();

        $returnData = [
            'order_id' => $order->id,
            'type' => 'exchange',
            'reason' => 'Wrong size',
            'items' => [
                [
                    'order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'quantity' => 1,
                    'condition' => 'new',
                    'restock' => true,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('returns.store'), $returnData);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('return_orders', [
            'order_id' => $order->id,
            'type' => 'exchange',
        ]);
    }
}
