<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Warehouses\WarehouseAccessFixture;
use Tests\TestCase;

/**
 * Approving or rejecting a sales order directly (the order page, REST)
 * follows the same warehouse rule as the approvals inbox: an approver
 * restricted to other warehouses cannot decide it.
 */
final class OrderApprovalWarehouseAccessTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    private User $approverA;

    private User $approverB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        $this->setUpWarehouseAccess();

        $this->organization->update(['settings' => ['approvals' => ['orders_enabled' => true]]]);

        $role = Role::create([
            'name' => 'Order approver', 'slug' => 'order-approver', 'organization_id' => $this->organization->id, 'is_system' => false,
            'permissions' => ['view_orders', 'edit_orders', 'approve_orders'],
        ]);

        $this->approverA = User::factory()->forOrganization($this->organization->id)->create(['name' => 'Alpha Approver']);
        $this->approverA->roles()->attach($role->id);
        $this->warehouseA->users()->attach($this->approverA->id);

        $this->approverB = User::factory()->forOrganization($this->organization->id)->create(['name' => 'Bravo Approver']);
        $this->approverB->roles()->attach($role->id);
        $this->warehouseB->users()->attach($this->approverB->id);
    }

    private function pendingOrderInB(): Order
    {
        $this->actingAs($this->admin);
        $order = app(OrderService::class)->create([
            'customer_name' => 'Buyer',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouseB->id,
            'items' => [['product_id' => $this->productB->id, 'quantity' => 2, 'unit_price' => 10]],
        ], $this->admin);
        $this->app['auth']->forgetGuards();

        $this->assertTrue($order->fresh()->isPendingApproval());

        return $order->fresh();
    }

    private function assertStillPending(Order $order): void
    {
        $fresh = $order->fresh();
        $this->assertSame(OrderApprovalStatus::PENDING, $fresh->approval_status);
        $this->assertSame(OrderStatus::PENDING, $fresh->status);
        $this->assertNull($fresh->approved_by);
    }

    public function test_an_approver_of_another_warehouse_cannot_approve_or_reject_on_the_order_page(): void
    {
        $order = $this->pendingOrderInB();
        $stock = (int) $this->productB->fresh()->stock;

        $this->actingAs($this->approverA)
            ->post(route('orders.approve', $order), ['notes' => 'ok'])
            ->assertForbidden();
        $this->actingAs($this->approverA)
            ->post(route('orders.reject', $order), ['notes' => 'no'])
            ->assertForbidden();

        $this->assertStillPending($order);
        $this->assertSame($stock, (int) $this->productB->fresh()->stock);
    }

    public function test_an_approver_of_another_warehouse_cannot_approve_or_reject_through_the_api(): void
    {
        $order = $this->pendingOrderInB();

        Sanctum::actingAs($this->approverA, ['*']);
        $this->postJson("/api/v1/orders/{$order->id}/approve", ['notes' => 'ok'])->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->id}/reject", ['notes' => 'no'])->assertForbidden();

        $this->assertStillPending($order);
    }

    public function test_the_service_refuses_an_approver_without_access_to_the_orders_warehouse(): void
    {
        $order = $this->pendingOrderInB();

        try {
            app(OrderService::class)->approve($order, $this->approverA);
            $this->fail('Approving an order outside the approver\'s warehouses should be refused.');
        } catch (AuthorizationException) {
        }

        try {
            app(OrderService::class)->reject($order, $this->approverA, 'no');
            $this->fail('Rejecting an order outside the approver\'s warehouses should be refused.');
        } catch (AuthorizationException) {
        }

        $this->assertStillPending($order);
    }

    public function test_an_approver_of_the_orders_warehouse_can_approve_it(): void
    {
        $order = $this->pendingOrderInB();

        $this->actingAs($this->approverB)
            ->post(route('orders.approve', $order), ['notes' => 'ok'])
            ->assertSessionHas('success');

        $this->assertSame(OrderApprovalStatus::APPROVED, $order->fresh()->approval_status);
        $this->assertSame($this->approverB->id, $order->fresh()->approved_by);
    }

    public function test_an_approver_of_the_orders_warehouse_can_reject_it_through_the_api(): void
    {
        $order = $this->pendingOrderInB();

        Sanctum::actingAs($this->approverB, ['*']);
        $this->postJson("/api/v1/orders/{$order->id}/reject", ['notes' => 'no'])->assertOk();

        $this->assertSame(OrderApprovalStatus::REJECTED, $order->fresh()->approval_status);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_a_restricted_approver_can_approve_an_order_with_no_warehouse(): void
    {
        // REST, GraphQL and MCP create orders without a warehouse; restricted
        // approvers must still be able to decide them.
        $this->actingAs($this->admin);
        $order = app(OrderService::class)->create([
            'customer_name' => 'Buyer', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price' => 10]],
        ], $this->admin);
        $other = app(OrderService::class)->create([
            'customer_name' => 'Buyer', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price' => 10]],
        ], $this->admin);
        $this->app['auth']->forgetGuards();
        $this->assertNull($order->fresh()->warehouse_id);

        $this->actingAs($this->approverA)
            ->post(route('orders.approve', $order), ['notes' => 'ok'])
            ->assertSessionHas('success');
        $this->assertSame(OrderApprovalStatus::APPROVED, $order->fresh()->approval_status);

        Sanctum::actingAs($this->approverA, ['*']);
        $this->postJson("/api/v1/orders/{$other->id}/approve", ['notes' => 'ok'])->assertOk();
        $this->assertSame(OrderApprovalStatus::APPROVED, $other->fresh()->approval_status);

        // An order in a warehouse they are not assigned to stays out of reach.
        $inB = $this->pendingOrderInB();
        Sanctum::actingAs($this->approverA, ['*']);
        $this->postJson("/api/v1/orders/{$inB->id}/approve", ['notes' => 'ok'])->assertForbidden();
        $this->assertStillPending($inB);
    }

    public function test_an_unrestricted_admin_can_still_approve(): void
    {
        $order = $this->pendingOrderInB();
        $approver = User::factory()->admin()->forOrganization($this->organization->id)->create(['name' => 'Second Admin']);

        app(OrderService::class)->approve($order, $approver);

        $this->assertSame(OrderApprovalStatus::APPROVED, $order->fresh()->approval_status);
    }
}
