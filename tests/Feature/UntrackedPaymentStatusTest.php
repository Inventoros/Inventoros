<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\OrderPayment;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Documents\DocumentPdfService;
use App\Services\OrderPaymentService;
use App\Services\OrderService;
use App\Services\ReceivablesAgingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Orders that existed before payment tracking shipped carry no payment rows.
 * They are `untracked`, not `unpaid`: they must not show up as money owed
 * anywhere, and they move into normal tracking once a payment is recorded.
 */
class UntrackedPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_30_121547_mark_pre_tracking_orders_payment_untracked.php';

    protected Organization $organization;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-27 12:00:00');
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Legacy Org', 'email' => 'legacy@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->organization->id, 'sku' => 'LEG-1', 'name' => 'Thing',
            'price' => 25.00, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@legacy.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function legacyOrder(string $number, string $date = '2026-01-10', float $total = 100, string $status = 'delivered', ?int $organizationId = null): Order
    {
        return Order::create([
            'organization_id' => $organizationId ?? $this->organization->id,
            'order_number' => $number,
            'customer_name' => 'Acme',
            'status' => $status,
            'order_date' => $date,
            'total' => $total,
            'amount_paid' => 0,
            'payment_status' => 'untracked',
        ]);
    }

    private function runMigration(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    private function userWith(array $permissions, string $slug): User
    {
        $user = User::create([
            'name' => $slug, 'email' => "{$slug}@legacy.test", 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'member',
        ]);
        $role = Role::create(['name' => $slug, 'slug' => $slug, 'is_system' => false, 'permissions' => $permissions]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    // ------------------------------------------------------------ migration

    public function test_the_migration_marks_existing_orders_without_payments_untracked(): void
    {
        $legacy = Order::create([
            'organization_id' => $this->organization->id, 'order_number' => 'OLD-1', 'status' => 'delivered',
            'order_date' => '2026-01-01', 'total' => 80, 'amount_paid' => 0, 'payment_status' => 'unpaid',
        ]);
        $tracked = Order::create([
            'organization_id' => $this->organization->id, 'order_number' => 'NEW-1', 'status' => 'pending',
            'order_date' => '2026-09-01', 'total' => 80, 'amount_paid' => 30, 'payment_status' => 'partial',
        ]);
        OrderPayment::create([
            'organization_id' => $this->organization->id, 'order_id' => $tracked->id, 'type' => 'payment',
            'amount' => 30, 'method' => 'card', 'paid_at' => now(),
        ]);

        $this->runMigration();

        $this->assertSame(PaymentStatus::UNTRACKED, $legacy->fresh()->payment_status);
        $this->assertSame(PaymentStatus::PARTIAL, $tracked->fresh()->payment_status);

        // Running it again is harmless.
        $this->runMigration();
        $this->assertSame(PaymentStatus::UNTRACKED, $legacy->fresh()->payment_status);
    }

    public function test_orders_created_after_the_migration_are_tracked_as_unpaid(): void
    {
        $this->runMigration();

        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 25.00]],
        ], $this->admin)->fresh();

        $this->assertSame(PaymentStatus::UNPAID, $order->payment_status);
        $this->assertSame('100.00', $order->balanceDue());
    }

    // --------------------------------------------------------------- model

    public function test_an_untracked_order_owes_nothing_and_stays_untracked_when_resynced(): void
    {
        $order = $this->legacyOrder('OLD-1');

        $this->assertSame('0.00', $order->balanceDue());
        $this->assertFalse($order->isPaymentTracked());

        $order->syncPaymentState();
        $this->assertSame(PaymentStatus::UNTRACKED, $order->payment_status);
    }

    public function test_recording_the_first_payment_moves_the_order_into_tracking(): void
    {
        $order = $this->legacyOrder('OLD-1');

        app(OrderPaymentService::class)->record($order, $this->admin, ['amount' => 40, 'method' => 'cash']);

        $order->refresh();
        $this->assertSame(PaymentStatus::PARTIAL, $order->payment_status);
        $this->assertSame('60.00', $order->balanceDue());
    }

    public function test_the_first_payment_is_checked_against_the_order_total(): void
    {
        $order = $this->legacyOrder('OLD-1');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(OrderPaymentService::class)->record($order, $this->admin, ['amount' => 150, 'method' => 'cash']);
    }

    // ------------------------------------------------------------- surfaces

    public function test_untracked_orders_are_left_out_of_receivables_aging(): void
    {
        $this->legacyOrder('OLD-1', '2026-01-10', 500);
        Order::create([
            'organization_id' => $this->organization->id, 'order_number' => 'NEW-1', 'status' => 'pending',
            'order_date' => '2026-09-27', 'total' => 40, 'amount_paid' => 0, 'payment_status' => 'unpaid',
        ]);

        $report = app(ReceivablesAgingService::class)->build($this->organization->id);

        $this->assertSame('40.00', $report['summary']['total_outstanding']);
        $this->assertSame(1, $report['summary']['order_count']);
        $this->assertSame(0, collect($report['buckets'])->firstWhere('key', 'over_90')['count']);
    }

    public function test_untracked_orders_are_left_out_of_the_dashboard_receivables_tile(): void
    {
        $this->legacyOrder('OLD-1', '2026-01-10', 500, 'pending');
        Order::create([
            'organization_id' => $this->organization->id, 'order_number' => 'NEW-1', 'status' => 'pending',
            'order_date' => now(), 'total' => 40, 'amount_paid' => 0, 'payment_status' => 'unpaid',
        ]);

        $this->actingAs($this->userWith(['view_orders', 'view_reports', 'view_payments'], 'dash'))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('stats.outstandingReceivables', 40)->etc());
    }

    public function test_sales_analysis_counts_untracked_orders_but_not_as_outstanding(): void
    {
        $this->legacyOrder('OLD-1', '2026-09-20', 500, 'pending');

        $this->actingAs($this->admin)->get(route('reports.sales-analysis'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('byPaymentStatus.0.payment_status', 'untracked')
                ->where('byPaymentStatus.0.balance_due', 0)
                ->where('summary.total_outstanding', 0)
                ->etc()
            );
    }

    public function test_the_invoice_shows_nothing_about_payment_for_an_untracked_order(): void
    {
        $order = $this->legacyOrder('OLD-1');

        $html = view('pdf.invoice', app(DocumentPdfService::class)->orderInvoiceViewData($order->fresh()))->render();

        $this->assertStringNotContainsString('Balance due', $html);
        $this->assertStringNotContainsString('Amount paid', $html);
    }

    public function test_the_orders_filter_offers_not_tracked(): void
    {
        $this->legacyOrder('OLD-1');
        Order::create([
            'organization_id' => $this->organization->id, 'order_number' => 'NEW-1', 'status' => 'pending',
            'order_date' => now(), 'total' => 40, 'amount_paid' => 0, 'payment_status' => 'unpaid',
        ]);

        $this->actingAs($this->admin)->get(route('orders.index', ['payment_status' => 'untracked']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentStatuses', fn ($statuses) => collect($statuses)->contains('untracked'))
                ->has('orders.data', 1)
                ->where('orders.data.0.order_number', 'OLD-1')
                ->where('untrackedOrderCount', 1)
                ->etc()
            );
    }

    // ------------------------------------------------------------ bulk action

    public function test_mark_before_date_as_paid_records_a_reconciling_payment(): void
    {
        $old = $this->legacyOrder('OLD-1', '2026-01-10', 100);
        $zero = $this->legacyOrder('OLD-2', '2026-02-10', 0);
        $cancelled = $this->legacyOrder('OLD-3', '2026-02-11', 70, 'cancelled');
        $recent = $this->legacyOrder('OLD-4', '2026-06-10', 60);
        $otherOrg = Organization::create(['name' => 'Other', 'email' => 'o@o.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $foreign = $this->legacyOrder('X-1', '2026-01-10', 90, 'delivered', $otherOrg->id);

        $this->actingAs($this->admin)
            ->post(route('orders.payments.mark-pre-tracking-paid'), ['before' => '2026-03-01'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $old->refresh();
        $this->assertSame(PaymentStatus::PAID, $old->payment_status);
        $this->assertSame('100.00', (string) $old->amount_paid);
        $payment = $old->payments()->withoutGlobalScopes()->sole();
        $this->assertSame('other', $payment->method->value);
        $this->assertSame('Marked paid (pre-tracking)', $payment->reference);
        $this->assertSame('100.00', (string) $payment->amount);
        $this->assertSame('2026-01-10', $payment->paid_at->toDateString());

        $this->assertSame(PaymentStatus::PAID, $zero->fresh()->payment_status);
        $this->assertSame(PaymentStatus::UNTRACKED, $cancelled->fresh()->payment_status);
        $this->assertSame(PaymentStatus::UNTRACKED, $recent->fresh()->payment_status);
        $this->assertSame(PaymentStatus::UNTRACKED, Order::withoutGlobalScopes()->find($foreign->id)->payment_status);

        $this->assertTrue(ActivityLog::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('action', 'pre_tracking_marked_paid')
            ->exists());
    }

    public function test_mark_as_paid_needs_record_payments(): void
    {
        $order = $this->legacyOrder('OLD-1');

        $this->actingAs($this->userWith(['view_orders', 'view_payments'], 'viewer'))
            ->post(route('orders.payments.mark-pre-tracking-paid'), ['before' => '2026-03-01'])
            ->assertForbidden();

        $this->assertSame(PaymentStatus::UNTRACKED, $order->fresh()->payment_status);
    }

    public function test_mark_as_paid_validates_the_date(): void
    {
        $this->actingAs($this->admin)
            ->post(route('orders.payments.mark-pre-tracking-paid'), ['before' => 'not-a-date'])
            ->assertSessionHasErrors('before');
    }
}
