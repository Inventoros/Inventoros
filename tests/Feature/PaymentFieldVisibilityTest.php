<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Documents\DocumentPdfService;
use App\Services\OrderPaymentService;
use App\Services\OrderService;
use App\Services\ReturnOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Payment data (amount paid, payment status, the payment rows and their
 * references) is behind view_payments on every page that carries an order,
 * not only on the order pages that serialize through OrderResource, and on
 * the staff invoice PDF.
 */
final class PaymentFieldVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private User $clerk;   // everything below, but no view_payments

    private User $viewer;  // the same plus view_payments

    private Product $product;

    private Customer $customer;

    /** @var array<int, string> */
    private array $staffPermissions = [
        'view_orders', 'edit_orders', 'view_customers', 'manage_returns',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Visibility Org', 'email' => 'vis@org.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->organization->id, 'sku' => 'VIS-1', 'name' => 'Item',
            'price' => 50.00, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->customer = Customer::create([
            'organization_id' => $this->organization->id, 'name' => 'Acme Ltd', 'email' => 'acme@example.test',
        ]);
        $this->admin = $this->user('admin', 'admin');
        $this->clerk = $this->user('clerk', 'member', $this->staffPermissions);
        $this->viewer = $this->user('viewer', 'member', [...$this->staffPermissions, 'view_payments']);
    }

    private function user(string $name, string $role, array $permissions = []): User
    {
        $user = User::create([
            'name' => ucfirst($name), 'email' => "{$name}@vis.test", 'password' => bcrypt('password'),
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

    /** A 100.00 order with a 30.00 card payment referenced TX-SECRET-77. */
    private function paidOrder(string $status = 'pending'): Order
    {
        $order = app(OrderService::class)->create([
            'customer_id' => $this->customer->id, 'customer_name' => 'Acme Ltd',
            'status' => $status, 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 50]],
        ], $this->admin);
        app(OrderPaymentService::class)->record($order, $this->admin, [
            'amount' => '30.00', 'method' => 'card', 'reference' => 'TX-SECRET-77',
        ]);

        return $order->fresh();
    }

    private function deliveredOrderWithReturn(): array
    {
        $order = $this->paidOrder('delivered')->load('items');
        $this->actingAs($this->admin);
        $return = app(ReturnOrderService::class)->create($this->organization->id, $this->admin, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Unwanted',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1, 'condition' => 'new', 'restock' => true]],
        ]);
        auth()->forgetGuards();

        return [$order, $return];
    }

    // ------------------------------------------------------------------
    // Raw order props on Inertia pages.
    // ------------------------------------------------------------------

    public function test_the_customer_page_hides_payment_fields_without_view_payments(): void
    {
        $this->paidOrder();

        $this->actingAs($this->clerk)->get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customer.orders', 1)
                ->missing('customer.orders.0.amount_paid')
                ->missing('customer.orders.0.payment_status')
                ->missing('customer.orders.0.payments')
                ->etc());

        $this->actingAs($this->viewer)->get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customer.orders.0.amount_paid', '30.00')
                ->where('customer.orders.0.payment_status', 'partial')
                ->etc());
    }

    public function test_the_order_edit_page_hides_payment_fields_without_view_payments(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($this->clerk)->get(route('orders.edit', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('order.id')
                ->missing('order.amount_paid')
                ->missing('order.payment_status')
                ->missing('order.payments')
                ->etc());

        $this->actingAs($this->viewer)->get(route('orders.edit', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('order.amount_paid', '30.00')->etc());
    }

    public function test_the_return_pages_hide_the_orders_payment_fields_without_view_payments(): void
    {
        [$order, $return] = $this->deliveredOrderWithReturn();

        $this->actingAs($this->clerk)->get(route('returns.create', ['order_id' => $order->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('order.id')
                ->missing('order.amount_paid')
                ->missing('order.payment_status')
                ->etc());

        $this->actingAs($this->clerk)->get(route('returns.show', $return))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('returnOrder.order.id')
                ->missing('returnOrder.order.amount_paid')
                ->missing('returnOrder.order.payment_status')
                ->etc());

        $this->actingAs($this->clerk)->get(route('returns.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('returns.data.0.order.id')
                ->missing('returns.data.0.order.amount_paid')
                ->missing('returns.data.0.order.payment_status')
                ->etc());

        $this->actingAs($this->viewer)->get(route('returns.show', $return))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('returnOrder.order.amount_paid', '30.00')->etc());
    }

    public function test_the_dashboard_recent_orders_hide_payment_fields_without_view_payments(): void
    {
        $this->paidOrder();

        $this->actingAs($this->clerk)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentOrders', 1)
                ->missing('recentOrders.0.amount_paid')
                ->missing('recentOrders.0.payment_status')
                ->etc());

        $this->actingAs($this->viewer)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recentOrders.0.amount_paid', '30.00')->etc());
    }

    // ------------------------------------------------------------------
    // The staff invoice PDF.
    // ------------------------------------------------------------------

    /**
     * Capture the data the invoice view is rendered with for a request.
     *
     * @return array<string, mixed>
     */
    private function invoiceViewDataFor(User $user, Order $order): array
    {
        $captured = [];
        View::composer('pdf.invoice', function ($view) use (&$captured): void {
            $captured = $view->getData();
        });

        $this->actingAs($user)->get(route('orders.invoice.download', $order))->assertOk();

        return $captured;
    }

    public function test_the_staff_invoice_leaves_payments_off_without_view_payments(): void
    {
        $order = $this->paidOrder();

        $data = $this->invoiceViewDataFor($this->clerk, $order);
        $this->assertNotSame([], $data);
        $this->assertCount(0, $data['payments']);
        $this->assertFalse($data['showPaymentReferences']);
        $this->assertFalse($data['showPaymentPosition']);

        $html = view('pdf.invoice', $data)->render();
        $this->assertStringNotContainsString('TX-SECRET-77', $html);
        $this->assertStringNotContainsString('Amount paid', $html);
        $this->assertStringNotContainsString('Balance due', $html);
        $this->assertStringContainsString('100.00', $html); // the total is still there
    }

    public function test_the_staff_invoice_keeps_payments_for_payment_viewers(): void
    {
        $order = $this->paidOrder();

        $data = $this->invoiceViewDataFor($this->viewer, $order);
        $this->assertCount(1, $data['payments']);

        $html = view('pdf.invoice', $data)->render();
        $this->assertStringContainsString('TX-SECRET-77', $html);
        $this->assertStringContainsString('Amount paid', $html);
        $this->assertStringContainsString('Balance due', $html);
    }

    public function test_the_customer_portal_copy_is_unchanged(): void
    {
        $order = $this->paidOrder();

        $data = app(DocumentPdfService::class)->orderInvoiceViewData($order, forCustomerPortal: true);

        $this->assertCount(1, $data['payments']);
        $this->assertFalse($data['showPaymentReferences']);
        $this->assertTrue($data['showPaymentPosition']);
    }
}
