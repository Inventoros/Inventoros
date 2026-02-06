<?php

namespace Tests\Unit;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Notification;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;
    protected User $user;
    protected Role $stockRole;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Org',
            'email' => 'test@org.com',
        ]);

        $this->stockRole = Role::create([
            'name' => 'Stock Manager',
            'slug' => 'stock-manager',
            'organization_id' => $this->organization->id,
            'permissions' => ['manage_stock', 'view_orders'],
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->user->roles()->attach($this->stockRole->id);

        $this->actingAs($this->user);

        Mail::fake();
    }

    // ========================================
    // Low Stock Notifications
    // ========================================

    public function test_create_low_stock_notification(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Product',
            'sku' => 'TST-001',
            'price' => 10,
            'stock' => 3,
            'min_stock' => 5,
        ]);

        NotificationService::createLowStockNotification($product);

        $this->assertDatabaseHas('notifications', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'type' => 'low_stock',
            'title' => 'Low Stock Alert',
            'priority' => 'high',
        ]);
    }

    public function test_low_stock_notification_is_urgent_when_zero_stock(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Product',
            'sku' => 'TST-002',
            'price' => 10,
            'stock' => 0,
            'min_stock' => 5,
        ]);

        NotificationService::createLowStockNotification($product);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'type' => 'low_stock',
            'priority' => 'urgent',
        ]);
    }

    public function test_low_stock_skips_user_with_disabled_preference(): void
    {
        $this->user->update([
            'notification_preferences' => ['low_stock_alerts' => false],
        ]);

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Product',
            'sku' => 'TST-003',
            'price' => 10,
            'stock' => 2,
            'min_stock' => 5,
        ]);

        NotificationService::createLowStockNotification($product);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->user->id,
            'type' => 'low_stock',
        ]);
    }

    public function test_low_stock_only_notifies_users_with_manage_stock_permission(): void
    {
        $viewerRole = Role::create([
            'name' => 'Viewer',
            'slug' => 'viewer',
            'organization_id' => $this->organization->id,
            'permissions' => ['view_products'],
        ]);

        $viewer = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $viewer->roles()->attach($viewerRole->id);

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Product',
            'sku' => 'TST-004',
            'price' => 10,
            'stock' => 2,
            'min_stock' => 5,
        ]);

        NotificationService::createLowStockNotification($product);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'type' => 'low_stock',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $viewer->id,
            'type' => 'low_stock',
        ]);
    }

    // ========================================
    // Out of Stock Notifications
    // ========================================

    public function test_create_out_of_stock_notification(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Out Product',
            'sku' => 'OUT-001',
            'price' => 10,
            'stock' => 0,
            'min_stock' => 5,
        ]);

        NotificationService::createOutOfStockNotification($product);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'type' => 'out_of_stock',
            'title' => 'Out of Stock Alert',
            'priority' => 'urgent',
        ]);
    }

    // ========================================
    // Order Created Notifications
    // ========================================

    public function test_create_order_created_notification(): void
    {
        // Create a second user who will be the order creator
        $creator = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $order = Order::create([
            'organization_id' => $this->organization->id,
            'created_by' => $creator->id,
            'order_number' => 'ORD-001',
            'customer_name' => 'John Doe',
            'status' => 'pending',
            'total' => 100.00,
        ]);

        NotificationService::createOrderCreatedNotification($order);

        // The user with view_orders should get notified (not the creator)
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'type' => 'order_created',
            'title' => 'New Order Created',
        ]);

        // Creator should NOT get notified
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $creator->id,
            'type' => 'order_created',
        ]);
    }

    // ========================================
    // Order Status Notifications
    // ========================================

    public function test_create_order_status_notification(): void
    {
        $order = Order::create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->user->id,
            'order_number' => 'ORD-002',
            'customer_name' => 'Jane Doe',
            'status' => 'shipped',
            'total' => 50.00,
        ]);

        NotificationService::createOrderStatusNotification($order, 'pending');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'type' => 'order_status_updated',
            'priority' => 'normal',
        ]);
    }

    public function test_order_status_notification_high_priority_for_cancelled(): void
    {
        $order = Order::create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->user->id,
            'order_number' => 'ORD-003',
            'customer_name' => 'Jane Doe',
            'status' => 'cancelled',
            'total' => 50.00,
        ]);

        NotificationService::createOrderStatusNotification($order, 'pending');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'type' => 'order_status_updated',
            'priority' => 'high',
        ]);
    }

    public function test_order_status_notification_skipped_when_user_preference_disabled(): void
    {
        $this->user->update([
            'notification_preferences' => ['order_notifications' => false],
        ]);

        $order = Order::create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->user->id,
            'order_number' => 'ORD-004',
            'customer_name' => 'Jane Doe',
            'status' => 'shipped',
            'total' => 50.00,
        ]);

        NotificationService::createOrderStatusNotification($order, 'pending');

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->user->id,
            'type' => 'order_status_updated',
        ]);
    }

    // ========================================
    // Order Shipped Notifications
    // ========================================

    public function test_create_order_shipped_notification(): void
    {
        $order = Order::create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->user->id,
            'order_number' => 'ORD-005',
            'customer_name' => 'Test Customer',
            'status' => 'shipped',
            'total' => 75.00,
        ]);

        NotificationService::createOrderShippedNotification($order);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'type' => 'order_shipped',
            'title' => 'Order Shipped',
            'priority' => 'normal',
        ]);
    }

    // ========================================
    // Order Delivered Notifications
    // ========================================

    public function test_create_order_delivered_notification(): void
    {
        $order = Order::create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->user->id,
            'order_number' => 'ORD-006',
            'customer_name' => 'Test Customer',
            'status' => 'delivered',
            'total' => 75.00,
        ]);

        NotificationService::createOrderDeliveredNotification($order);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'type' => 'order_delivered',
            'title' => 'Order Delivered',
            'priority' => 'normal',
        ]);
    }

    // ========================================
    // Order Approval Notifications
    // ========================================

    public function test_create_order_approval_notification_approved(): void
    {
        $approver = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $order = Order::create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->user->id,
            'order_number' => 'ORD-007',
            'customer_name' => 'Test Customer',
            'status' => 'approved',
            'approval_status' => 'approved',
            'approved_by' => $approver->id,
            'total' => 100.00,
        ]);

        NotificationService::createOrderApprovalNotification($order);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'type' => 'order_approved',
            'priority' => 'normal',
        ]);
    }

    public function test_create_order_approval_notification_rejected_is_high_priority(): void
    {
        $approver = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $order = Order::create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->user->id,
            'order_number' => 'ORD-008',
            'customer_name' => 'Test Customer',
            'status' => 'rejected',
            'approval_status' => 'rejected',
            'approved_by' => $approver->id,
            'approval_notes' => 'Budget exceeded',
            'total' => 100.00,
        ]);

        NotificationService::createOrderApprovalNotification($order);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'type' => 'order_rejected',
            'priority' => 'high',
        ]);
    }

    public function test_order_status_not_sent_when_creator_not_found(): void
    {
        // Create order with a valid user, then delete the user
        $tempUser = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $order = Order::create([
            'organization_id' => $this->organization->id,
            'created_by' => $tempUser->id,
            'order_number' => 'ORD-009',
            'customer_name' => 'Test',
            'status' => 'shipped',
            'total' => 10.00,
        ]);

        // Delete the user (nullOnDelete sets created_by to null)
        $tempUser->forceDelete();
        $order->refresh();

        NotificationService::createOrderStatusNotification($order, 'pending');

        $this->assertDatabaseMissing('notifications', [
            'type' => 'order_status_updated',
        ]);
    }
}
