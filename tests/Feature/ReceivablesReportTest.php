<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Outstanding balances (accounts receivable aging): what customers still owe
 * on live orders, bucketed by the age of the order date.
 */
class ReceivablesReportTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-27 12:00:00');
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Aging Org', 'email' => 'aging@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@aging.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function order(string $number, string $date, float $total, float $paid, string $customer = 'Acme', string $status = 'pending'): Order
    {
        return Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => $number,
            'customer_name' => $customer,
            'status' => $status,
            'order_date' => $date,
            'total' => $total,
            'amount_paid' => $paid,
            'payment_status' => $paid <= 0 ? 'unpaid' : ($paid < $total ? 'partial' : 'paid'),
        ]);
    }

    /** @param array<int, string> $permissions */
    private function userWith(array $permissions, string $slug): User
    {
        $user = User::create([
            'name' => $slug, 'email' => "{$slug}@aging.test", 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'member',
        ]);
        $role = Role::create(['name' => $slug, 'slug' => $slug, 'is_system' => false, 'permissions' => $permissions]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    public function test_balances_are_bucketed_by_order_age(): void
    {
        $this->order('ORD-A', '2026-09-27 08:00:00', 100, 0);          // today: current
        $this->order('ORD-B', '2026-09-26 23:00:00', 50, 20);          // 1 day: 1-30 (owes 30)
        $this->order('ORD-C', '2026-08-28 10:00:00', 40, 0, 'Beta');   // 30 days: 1-30
        $this->order('ORD-D', '2026-08-27 10:00:00', 10, 0, 'Beta');   // 31 days: 31-60
        $this->order('ORD-E', '2026-06-29 10:00:00', 25.5, 0, 'Gamma'); // 90 days: 61-90
        $this->order('ORD-F', '2026-06-28 10:00:00', 7.25, 0, 'Gamma'); // 91 days: 90+
        // Not receivables: paid in full, cancelled, another organization.
        $this->order('ORD-G', '2026-01-01 10:00:00', 80, 80);
        $this->order('ORD-H', '2026-01-01 10:00:00', 999, 0, 'Acme', 'cancelled');
        $other = Organization::create(['name' => 'O', 'email' => 'o@aging.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        Order::create(['organization_id' => $other->id, 'order_number' => 'ORD-X', 'status' => 'pending', 'order_date' => now(), 'total' => 500]);

        $this->actingAs($this->admin)->get(route('reports.receivables'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Receivables')
                ->where('summary.total_outstanding', '212.75')
                ->where('summary.order_count', 6)
                ->where('buckets.0.key', 'current')
                ->where('buckets.0.amount', '100.00')
                ->where('buckets.0.count', 1)
                ->where('buckets.1.key', '1_30')
                ->where('buckets.1.amount', '70.00')
                ->where('buckets.1.count', 2)
                ->where('buckets.2.key', '31_60')
                ->where('buckets.2.amount', '10.00')
                ->where('buckets.3.key', '61_90')
                ->where('buckets.3.amount', '25.50')
                ->where('buckets.4.key', 'over_90')
                ->where('buckets.4.amount', '7.25')
                ->has('customers', 3)
                ->where('customers.0.customer', 'Acme')
                ->where('customers.0.total', '130.00')
                ->where('customers.0.current', '100.00')
                ->where('customers.0.1_30', '30.00')
                // Oldest first, so the most overdue orders are on top.
                ->where('orders.0.order_number', 'ORD-F')
                ->where('orders.0.balance_due', '7.25')
                ->where('orders.0.age_days', 91)
                ->where('orders.0.bucket', 'over_90')
                ->has('orders', 6)
            );
    }

    public function test_it_needs_view_reports_and_view_payments(): void
    {
        $this->actingAs($this->userWith(['view_reports'], 'reports-only'))
            ->get(route('reports.receivables'))->assertForbidden();

        $this->actingAs($this->userWith(['view_payments', 'view_orders'], 'payments-only'))
            ->get(route('reports.receivables'))->assertForbidden();

        $this->actingAs($this->userWith(['view_reports', 'view_payments'], 'both'))
            ->get(route('reports.receivables'))->assertOk();
    }

    public function test_sales_analysis_breaks_orders_down_by_payment_status_for_payment_viewers(): void
    {
        $this->order('ORD-P1', '2026-09-20 10:00:00', 100, 100);
        $this->order('ORD-P2', '2026-09-21 10:00:00', 50, 20);
        $this->order('ORD-P3', '2026-09-22 10:00:00', 30, 0);

        $this->actingAs($this->admin)->get(route('reports.sales-analysis'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('byPaymentStatus', 3)
                // In lifecycle order: unpaid, partial, paid, ...
                ->where('byPaymentStatus.0.payment_status', 'unpaid')
                ->where('byPaymentStatus.0.balance_due', 30)
                ->where('byPaymentStatus.2.payment_status', 'paid')
                ->where('byPaymentStatus.2.count', 1)
                ->where('byPaymentStatus.2.total', 100)
                ->where('byPaymentStatus.1.payment_status', 'partial')
                ->where('byPaymentStatus.1.amount_paid', 20)
                ->where('byPaymentStatus.1.balance_due', 30)
                ->where('summary.total_outstanding', 60)
                ->etc()
            );

        $this->actingAs($this->userWith(['view_reports'], 'sales-no-pay'))->get(route('reports.sales-analysis'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('byPaymentStatus')
                ->missing('summary.total_outstanding')
                ->etc()
            );
    }
}
