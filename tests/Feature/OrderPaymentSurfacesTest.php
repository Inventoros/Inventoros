<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\GetOrderTool;
use App\Mcp\Tools\RecordPaymentTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\OrderPayment;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payments through the web order pages, the REST API, GraphQL and MCP, with
 * view_payments / record_payments enforced on every surface.
 */
class OrderPaymentSurfacesTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $admin;

    protected User $clerk;   // view_orders only

    protected User $viewer;  // view_orders + view_payments

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Surf Pay', 'email' => 'surfpay@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->organization->id, 'sku' => 'SP-1', 'name' => 'Item',
            'price' => 50.00, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->admin = $this->user('admin', 'admin');
        $this->clerk = $this->user('clerk', 'member', ['view_orders', 'edit_orders']);
        $this->viewer = $this->user('viewer', 'member', ['view_orders', 'view_payments']);
    }

    private function user(string $name, string $role, array $permissions = []): User
    {
        $user = User::create([
            'name' => ucfirst($name), 'email' => "{$name}@surfpay.test", 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => $role,
        ]);

        if ($permissions !== []) {
            $custom = Role::create([
                'name' => "Role {$name}", 'slug' => "role-{$name}", 'organization_id' => $this->organization->id,
                'is_system' => false, 'permissions' => $permissions,
            ]);
            $user->roles()->sync([$custom->id]);
        }

        return $user;
    }

    /** A 100.00 order. */
    private function order(): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 50]],
        ], $this->admin);
    }

    // ---------------------------------------------------------------- web

    public function test_web_records_a_payment(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->post(route('orders.payments.store', $order), [
            'amount' => '30.00', 'method' => 'bank_transfer', 'reference' => 'BT-1', 'paid_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect(route('orders.show', $order));

        $order->refresh();
        $this->assertSame('30.00', (string) $order->amount_paid);
        $this->assertSame(PaymentStatus::PARTIAL, $order->payment_status);
    }

    public function test_web_records_a_refund(): void
    {
        $order = $this->order();
        app(OrderPaymentService::class)->record($order, $this->admin, ['amount' => 100, 'method' => 'card']);

        $this->actingAs($this->admin)->post(route('orders.payments.store', $order), [
            'type' => 'refund', 'amount' => '100.00', 'method' => 'card',
        ])->assertSessionHasNoErrors();

        $this->assertSame(PaymentStatus::REFUNDED, $order->fresh()->payment_status);
    }

    public function test_web_overpayment_needs_the_flag(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->post(route('orders.payments.store', $order), ['amount' => '150', 'method' => 'cash'])
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->admin)->post(route('orders.payments.store', $order), ['amount' => '150', 'method' => 'cash', 'allow_overpayment' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(PaymentStatus::OVERPAID, $order->fresh()->payment_status);
    }

    public function test_web_validates_method_and_amount(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->post(route('orders.payments.store', $order), ['amount' => 'abc', 'method' => 'bitcoin'])
            ->assertSessionHasErrors(['amount', 'method']);
        $this->assertSame(0, OrderPayment::count());
    }

    public function test_web_voids_a_payment(): void
    {
        $order = $this->order();
        $payment = app(OrderPaymentService::class)->record($order, $this->admin, ['amount' => 20, 'method' => 'card']);

        $this->actingAs($this->admin)->post(route('orders.payments.void', [$order, $payment]), ['reason' => 'Duplicate'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame(PaymentStatus::UNPAID, $order->fresh()->payment_status);
    }

    public function test_web_void_rejects_a_payment_from_another_order(): void
    {
        $order = $this->order();
        $other = $this->order();
        $payment = app(OrderPaymentService::class)->record($other, $this->admin, ['amount' => 20, 'method' => 'card']);

        $this->actingAs($this->admin)->post(route('orders.payments.void', [$order, $payment]))->assertNotFound();
        $this->assertNull($payment->fresh()->voided_at);
    }

    public function test_web_payment_routes_need_record_payments(): void
    {
        $order = $this->order();
        $payment = app(OrderPaymentService::class)->record($order, $this->admin, ['amount' => 20, 'method' => 'card']);

        $this->actingAs($this->viewer)->post(route('orders.payments.store', $order), ['amount' => 1, 'method' => 'cash'])->assertForbidden();
        $this->actingAs($this->viewer)->post(route('orders.payments.void', [$order, $payment]))->assertForbidden();
        $this->assertSame(1, OrderPayment::count());
    }

    public function test_web_cannot_touch_another_organizations_order(): void
    {
        $otherOrg = Organization::create(['name' => 'Other', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $stranger = User::create([
            'name' => 'Stranger', 'email' => 'stranger@other.test', 'password' => bcrypt('x'),
            'organization_id' => $otherOrg->id, 'role' => 'admin',
        ]);
        $order = $this->order();

        $response = $this->actingAs($stranger)->post(route('orders.payments.store', $order), ['amount' => 1, 'method' => 'cash']);
        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame(0, OrderPayment::count());
    }

    public function test_show_includes_payments_for_viewers_and_hides_them_otherwise(): void
    {
        $order = $this->order();
        app(OrderPaymentService::class)->record($order, $this->admin, ['amount' => 40, 'method' => 'card', 'reference' => 'R-1']);

        $this->actingAs($this->viewer)->get(route('orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->where('order.amount_paid', '40.00')
                ->where('order.balance_due', '60.00')
                ->where('order.payment_status', 'partial')
                ->where('order.payments.0.reference', 'R-1')
                ->where('order.payments.0.method', 'card')
                ->where('order.payments.0.recorded_by.name', 'Admin')
                ->where('canRecordPayments', false)
            );

        $this->actingAs($this->clerk)->get(route('orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->missing('order.amount_paid')
                ->missing('order.balance_due')
                ->missing('order.payment_status')
                ->missing('order.payments')
                ->where('canRecordPayments', false)
            );

        $this->actingAs($this->admin)->get(route('orders.show', $order))
            ->assertInertia(fn ($page) => $page->where('canRecordPayments', true)->has('paymentMethods', 5));
    }

    public function test_index_shows_payment_status_and_filters_by_it_for_viewers_only(): void
    {
        $paid = $this->order();
        app(OrderPaymentService::class)->record($paid, $this->admin, ['amount' => 100, 'method' => 'card']);
        $this->order(); // unpaid

        $this->actingAs($this->viewer)->get(route('orders.index', ['payment_status' => 'paid']))
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $paid->id)
                ->where('orders.data.0.payment_status', 'paid')
                ->where('filters.payment_status', 'paid')
                ->where('canViewPayments', true)
                ->has('paymentStatuses', 5)
            );

        // Without view_payments the filter is ignored (it would otherwise leak
        // which orders are paid) and the status is absent.
        $this->actingAs($this->clerk)->get(route('orders.index', ['payment_status' => 'paid']))
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 2)
                ->missing('orders.data.0.payment_status')
                ->where('canViewPayments', false)
            );
    }

    // ---------------------------------------------------------------- REST

    public function test_rest_lists_records_refunds_and_voids(): void
    {
        $order = $this->order();
        Sanctum::actingAs($this->admin, ['*']);

        $created = $this->postJson("/api/v1/orders/{$order->id}/payments", [
            'amount' => 60, 'method' => 'cheque', 'reference' => 'CHQ-1',
        ])->assertCreated()
            ->assertJsonPath('data.amount', '60.00')
            ->assertJsonPath('data.method', 'cheque')
            ->assertJsonPath('order.payment_status', 'partial')
            ->assertJsonPath('order.balance_due', '40.00');

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['amount' => 50, 'method' => 'card'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->postJson("/api/v1/orders/{$order->id}/refunds", ['amount' => 10, 'method' => 'cheque'])
            ->assertCreated()->assertJsonPath('data.type', 'refund')->assertJsonPath('order.amount_paid', '50.00');

        $this->postJson("/api/v1/orders/{$order->id}/refunds", ['amount' => 51, 'method' => 'cheque'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $paymentId = $created->json('data.id');
        $this->postJson("/api/v1/orders/{$order->id}/payments/{$paymentId}/void", ['reason' => 'bounced'])
            ->assertStatus(422)->assertJsonValidationErrors('payment');

        $this->getJson("/api/v1/orders/{$order->id}/payments")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.type', 'refund');

        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertJsonPath('data.amount_paid', '50.00')->assertJsonPath('data.payment_status', 'partial');
    }

    public function test_rest_rejects_payments_on_cancelled_orders(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin);
        app(OrderService::class)->cancel($order);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['amount' => 10, 'method' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('order');
    }

    public function test_rest_permissions(): void
    {
        $order = $this->order();

        Sanctum::actingAs($this->clerk, ['*']);
        $this->getJson("/api/v1/orders/{$order->id}/payments")->assertForbidden();
        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonMissingPath('data.payment_status');

        Sanctum::actingAs($this->viewer, ['*']);
        $this->getJson("/api/v1/orders/{$order->id}/payments")->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['amount' => 1, 'method' => 'cash'])->assertForbidden();
    }

    public function test_rest_index_filters_by_payment_status(): void
    {
        $paid = $this->order();
        app(OrderPaymentService::class)->record($paid, $this->admin, ['amount' => 100, 'method' => 'card']);
        $this->order();

        Sanctum::actingAs($this->admin, ['*']);
        $this->getJson('/api/v1/orders?payment_status=unpaid')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_rest_cannot_read_another_organizations_payments(): void
    {
        $otherOrg = Organization::create(['name' => 'Other', 'email' => 'o2@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $stranger = User::create([
            'name' => 'Stranger', 'email' => 'stranger2@other.test', 'password' => bcrypt('x'),
            'organization_id' => $otherOrg->id, 'role' => 'admin',
        ]);
        $order = $this->order();

        Sanctum::actingAs($stranger, ['*']);
        $this->getJson("/api/v1/orders/{$order->id}/payments")->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['amount' => 1, 'method' => 'cash'])->assertNotFound();
        $this->assertSame(0, OrderPayment::count());
    }

    // ---------------------------------------------------------------- GraphQL

    public function test_graphql_exposes_payment_fields_only_with_view_payments(): void
    {
        $order = $this->order();
        app(OrderPaymentService::class)->record($order, $this->admin, ['amount' => 25, 'method' => 'cash']);
        $query = sprintf('{ order(id: %d) { amount_paid balance_due payment_status payments { amount method type } } }', $order->id);

        Sanctum::actingAs($this->viewer, ['*']);
        $this->postJson('/graphql', ['query' => $query])
            ->assertJsonPath('data.order.amount_paid', 25)
            ->assertJsonPath('data.order.balance_due', 75)
            ->assertJsonPath('data.order.payment_status', 'partial')
            ->assertJsonPath('data.order.payments.0.method', 'cash');

        Sanctum::actingAs($this->clerk, ['*']);
        $this->postJson('/graphql', ['query' => $query])
            ->assertJsonPath('data.order.amount_paid', null)
            ->assertJsonPath('data.order.payment_status', null)
            ->assertJsonPath('data.order.payments', null);
    }

    // ---------------------------------------------------------------- MCP

    public function test_mcp_record_payment_tool(): void
    {
        $order = $this->order();

        InventorosServer::actingAs($this->admin)
            ->tool(RecordPaymentTool::class, ['order_id' => $order->id, 'amount' => 45.5, 'method' => 'card', 'reference' => 'MCP-1'])
            ->assertOk()
            ->assertSee('partial');

        $this->assertSame('45.50', (string) $order->fresh()->amount_paid);
        $this->assertSame('MCP-1', OrderPayment::firstOrFail()->reference);
    }

    public function test_mcp_record_payment_rejects_overpayment_unless_allowed(): void
    {
        $order = $this->order();

        InventorosServer::actingAs($this->admin)
            ->tool(RecordPaymentTool::class, ['order_id' => $order->id, 'amount' => 150, 'method' => 'card'])
            ->assertHasErrors();
        $this->assertSame(0, OrderPayment::count());

        InventorosServer::actingAs($this->admin)
            ->tool(RecordPaymentTool::class, ['order_id' => $order->id, 'amount' => 150, 'method' => 'card', 'allow_overpayment' => true])
            ->assertOk();
        $this->assertSame(PaymentStatus::OVERPAID, $order->fresh()->payment_status);
    }

    public function test_mcp_record_payment_needs_record_payments_and_the_same_org(): void
    {
        $order = $this->order();

        InventorosServer::actingAs($this->viewer)
            ->tool(RecordPaymentTool::class, ['order_id' => $order->id, 'amount' => 10, 'method' => 'card'])
            ->assertHasErrors();

        $otherOrg = Organization::create(['name' => 'Other', 'email' => 'o3@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $stranger = User::create([
            'name' => 'Stranger', 'email' => 'stranger3@other.test', 'password' => bcrypt('x'),
            'organization_id' => $otherOrg->id, 'role' => 'admin',
        ]);
        InventorosServer::actingAs($stranger)
            ->tool(RecordPaymentTool::class, ['order_id' => $order->id, 'amount' => 10, 'method' => 'card'])
            ->assertHasErrors();

        $this->assertSame(0, OrderPayment::count());
    }

    public function test_mcp_get_order_includes_payment_position_only_with_view_payments(): void
    {
        $order = $this->order();
        app(OrderPaymentService::class)->record($order, $this->admin, ['amount' => 10, 'method' => 'card']);

        InventorosServer::actingAs($this->viewer)
            ->tool(GetOrderTool::class, ['id' => $order->id])
            ->assertOk()->assertSee('balance_due')->assertSee('discount_amount');

        $this->app['auth']->forgetGuards(); // drop the cached viewer

        InventorosServer::actingAs($this->clerk)
            ->tool(GetOrderTool::class, ['id' => $order->id])
            ->assertOk()->assertDontSee('balance_due');
    }
}
