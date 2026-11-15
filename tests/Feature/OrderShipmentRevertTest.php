<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStateException;
use App\Imports\OrdersImport;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ReturnOrderService;
use App\Services\Shipping\ShipmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Status only moves forward, with one narrow correction: an admin can move an
 * order that was marked shipped by mistake back to processing, as long as
 * nothing actually left (no shipment beyond pending/label created) and no
 * return was raised. Imports cannot create the shipped-but-open state either.
 */
final class OrderShipmentRevertTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $clerk;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Revert Org', 'email' => 'r@org.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'REV-1', 'name' => 'Revert Widget',
            'price' => 10.00, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@revert.test', 'password' => bcrypt('password'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->clerk = User::create([
            'name' => 'Clerk', 'email' => 'clerk@revert.test', 'password' => bcrypt('password'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $role = Role::create([
            'name' => 'Order clerk', 'slug' => 'order-clerk', 'organization_id' => $this->org->id,
            'is_system' => false, 'permissions' => ['view_orders', 'edit_orders'],
        ]);
        $this->clerk->roles()->sync([$role->id]);
    }

    /** Marked shipped by hand (shipped_at stamped), with no shipment record. */
    private function markedShipped(): Order
    {
        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'processing', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10.00]],
        ], $this->admin)->load('items');

        $order = app(OrderService::class)->transitionStatus($order, OrderStatus::SHIPPED);
        $this->assertNotNull($order->fresh()->shipped_at);

        return $order->fresh('items');
    }

    private function revert(Order $order, ?User $actor = null): Order
    {
        return app(OrderService::class)->revertMistakenShipment($order, $actor ?? $this->admin);
    }

    public function test_an_admin_can_move_a_mistakenly_shipped_order_back_to_processing(): void
    {
        $order = $this->markedShipped();

        $this->revert($order);

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::PROCESSING, $fresh->status);
        $this->assertNull($fresh->shipped_at);
        $this->assertTrue(ActivityLog::where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->where('action', 'shipment_reverted')
            ->where('user_id', $this->admin->id)
            ->exists());
        $this->assertSame(95, (int) $this->product->fresh()->stock, 'no stock moves');
    }

    public function test_it_is_allowed_while_a_shipment_is_only_pending(): void
    {
        $order = $this->markedShipped();
        app(ShipmentService::class)->create($order, ['carrier' => 'manual'], $this->admin);

        $this->revert($order);

        $this->assertSame(OrderStatus::PROCESSING, $order->fresh()->status);
    }

    public function test_it_is_refused_once_a_shipment_has_left(): void
    {
        $order = $this->markedShipped();
        $shipment = app(ShipmentService::class)->create($order, ['carrier' => 'manual'], $this->admin);
        app(ShipmentService::class)->markShipped($shipment, $this->admin);

        $this->expectException(InvalidStateException::class);

        try {
            $this->revert($order->fresh());
        } finally {
            $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
            $this->assertNotNull($order->fresh()->shipped_at);
        }
    }

    public function test_it_is_refused_when_the_order_has_returns(): void
    {
        $order = $this->markedShipped();
        $this->actingAs($this->admin);
        app(ReturnOrderService::class)->create($this->org->id, $this->admin, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Unwanted',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1, 'condition' => 'new', 'restock' => true]],
        ]);

        $this->expectException(InvalidStateException::class);

        try {
            $this->revert($order->fresh());
        } finally {
            $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
        }
    }

    public function test_only_an_admin_can_do_it(): void
    {
        $order = $this->markedShipped();

        try {
            $this->revert($order, $this->clerk);
            $this->fail('A non-admin reverted a shipped order.');
        } catch (AuthorizationException) {
        }

        Sanctum::actingAs($this->clerk, ['*']);
        $this->postJson("/api/v1/orders/{$order->id}/revert-shipment")->assertForbidden();

        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_it_only_applies_to_shipped_orders(): void
    {
        $order = $this->markedShipped();
        app(OrderService::class)->transitionStatus($order, OrderStatus::DELIVERED);

        $this->expectException(InvalidStateException::class);

        try {
            $this->revert($order->fresh());
        } finally {
            $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
        }
    }

    public function test_an_ordinary_status_update_still_cannot_do_it(): void
    {
        $order = $this->markedShipped();
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson("/api/v1/orders/{$order->id}", ['status' => 'processing'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_state_transition');

        $this->expectException(InvalidStateException::class);
        $order->fresh()->update(['status' => OrderStatus::PROCESSING]);
    }

    public function test_the_api_endpoint_reverts_for_an_admin_and_reports_refusals(): void
    {
        $order = $this->markedShipped();
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/orders/{$order->id}/revert-shipment")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.shipped_at', null);

        $this->postJson("/api/v1/orders/{$order->id}/revert-shipment")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_state_transition');
    }

    public function test_the_api_endpoint_cannot_reach_another_organizations_order(): void
    {
        $order = $this->markedShipped();
        $otherOrg = Organization::create(['name' => 'Other', 'email' => 'o@other.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $otherAdmin = User::create([
            'name' => 'Other Admin', 'email' => 'admin@other.test', 'password' => bcrypt('password'),
            'organization_id' => $otherOrg->id, 'role' => 'admin',
        ]);
        Sanctum::actingAs($otherAdmin, ['*']);

        $this->postJson("/api/v1/orders/{$order->id}/revert-shipment")->assertNotFound();

        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Imports cannot create an open order stamped shipped.
    // ------------------------------------------------------------------

    public function test_the_import_rejects_shipped_at_on_an_open_order(): void
    {
        $csv = "external_reference,order_date,status,customer_name,product_sku,quantity,unit_price,shipped_at\n"
            ."IMP-1,2026-01-05,pending,Acme,REV-1,1,10,2026-01-06\n"
            ."IMP-2,2026-01-05,processing,Acme,REV-1,1,10,2026-01-06\n"
            ."IMP-3,2026-01-05,,Acme,REV-1,1,10,2026-01-06\n"
            ."IMP-4,2026-01-05,shipped,Acme,REV-1,1,10,2026-01-06\n";
        $import = new OrdersImport($this->admin, false);
        Excel::import($import, UploadedFile::fake()->createWithContent('orders.csv', $csv));

        $stats = $import->getStats();
        $this->assertSame([2, 3, 4], array_column($stats['errors'], 'row'));
        $this->assertStringContainsString('shipped_at', implode(' ', $stats['errors'][0]['errors']));
        $this->assertSame(1, $stats['imported']);
        $this->assertSame(['IMP-4'], Order::pluck('external_reference')->all());
        $this->assertNotNull(Order::sole()->shipped_at);
    }
}
