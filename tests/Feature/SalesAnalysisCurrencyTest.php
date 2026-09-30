<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Sales Analysis added order totals across currencies: a EUR 129 order went
 * into the USD revenue, the daily rows, the outstanding figure and the
 * export. Money is now kept per currency like receivables and valuation:
 * the headline figures are the organization's currency and every other
 * currency is listed beside them.
 */
class SalesAnalysisCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Acme', 'email' => 'acme@example.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
            'organization_id' => $this->org->id,
        ]);
        $this->admin->forceFill(['role' => 'admin'])->save();

        $this->order('US-1', '2026-06-15 10:00:00', 100, 'USD', 2, 'processing');
        $this->order('US-2', '2026-06-15 12:00:00', 50, 'USD', 1, 'delivered');
        $this->order('EU-1', '2026-06-16 09:00:00', 129, 'EUR', 3, 'delivered');
    }

    private function order(string $number, string $date, float $total, string $currency, int $qty, string $status): void
    {
        $id = DB::table('orders')->insertGetId([
            'organization_id' => $this->org->id, 'order_number' => $number, 'status' => $status,
            'subtotal' => $total, 'tax' => 0, 'total' => $total, 'currency' => $currency,
            'amount_paid' => 0, 'payment_status' => 'unpaid',
            'order_date' => $date, 'created_at' => $date, 'updated_at' => $date,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $id, 'product_id' => null, 'product_name' => 'Line', 'sku' => 'L', 'quantity' => $qty,
            'unit_price' => $total / $qty, 'subtotal' => $total, 'tax' => 0, 'total' => $total,
            'created_at' => $date, 'updated_at' => $date,
        ]);
    }

    private function page()
    {
        return $this->actingAs($this->admin)
            ->get(route('reports.sales-analysis', ['date_from' => '2026-06-11', 'date_to' => '2026-06-20']))
            ->assertOk();
    }

    public function test_the_headline_revenue_is_the_organization_currency_only(): void
    {
        $this->page()->assertInertia(fn (Assert $page) => $page
            ->where('summary.currency', 'USD')
            ->where('summary.total_orders', 3)
            ->where('summary.total_items_sold', 6)
            ->where('summary.total_revenue', fn ($v) => (float) $v === 150.0)
            ->where('summary.average_order_value', fn ($v) => (float) $v === 75.0)
            ->has('summary.by_currency', 2)
            ->where('summary.by_currency.0.currency', 'USD')
            ->where('summary.by_currency.0.orders', 2)
            ->where('summary.by_currency.1.currency', 'EUR')
            ->where('summary.by_currency.1.revenue', fn ($v) => (float) $v === 129.0)
            ->where('summary.by_currency.1.average_order_value', fn ($v) => (float) $v === 129.0)
            ->where('comparison.current.total_revenue', fn ($v) => (float) $v === 150.0)
        );
    }

    public function test_daily_rows_status_rows_and_outstanding_keep_currencies_apart(): void
    {
        $this->page()->assertInertia(fn (Assert $page) => $page
            ->where('dailySales.0.date', '2026-06-15')
            ->where('dailySales.0.revenue', fn ($v) => (float) $v === 150.0)
            ->where('dailySales.1.date', '2026-06-16')
            ->where('dailySales.1.orders', 1)
            ->where('dailySales.1.revenue', fn ($v) => (float) $v === 0.0)
            ->where('dailySales.1.by_currency.1.currency', 'EUR')
            ->where('dailySales.1.by_currency.1.revenue', fn ($v) => (float) $v === 129.0)
            ->where('byStatus', fn ($rows) => (float) collect($rows)->firstWhere('status', 'delivered')['revenue'] === 50.0)
            ->where('summary.total_outstanding', fn ($v) => (float) $v === 150.0)
            ->where('summary.outstanding_by_currency.1.currency', 'EUR')
            ->where('summary.outstanding_by_currency.1.amount', fn ($v) => (float) $v === 129.0)
            ->where('byPaymentStatus.0.balance_due', fn ($v) => (float) $v === 150.0)
            ->where('byPaymentStatus.0.by_currency.1.balance_due', fn ($v) => (float) $v === 129.0)
        );
    }

    public function test_top_products_carry_their_currency(): void
    {
        $this->page()->assertInertia(fn (Assert $page) => $page
            ->has('topProducts', 2)
            ->where('topProducts.0.currency', 'USD')
            ->where('topProducts.0.revenue', fn ($v) => (float) $v === 150.0)
            ->where('topProducts.1.currency', 'EUR')
        );
    }

    public function test_the_exports_have_a_currency_column(): void
    {
        $daily = $this->actingAs($this->admin)
            ->get(route('reports.sales-analysis', ['date_from' => '2026-06-11', 'date_to' => '2026-06-20', 'export' => 'csv']))
            ->streamedContent();

        $this->assertStringContainsString('Date,Currency,Orders,Revenue', $daily);
        $this->assertStringContainsString('2026-06-15,USD,2,150', $daily);
        $this->assertStringContainsString('2026-06-16,EUR,1,129', $daily);
        $this->assertStringNotContainsString('2026-06-16,USD', $daily);

        $status = $this->actingAs($this->admin)
            ->get(route('reports.sales-analysis', ['date_from' => '2026-06-11', 'date_to' => '2026-06-20', 'export' => 'csv', 'group' => 'status']))
            ->streamedContent();
        $this->assertStringContainsString('Status,Currency,Orders,Revenue', $status);
        $this->assertStringContainsString('delivered,EUR,1,129', $status);

        $products = $this->actingAs($this->admin)
            ->get(route('reports.sales-analysis', ['date_from' => '2026-06-11', 'date_to' => '2026-06-20', 'export' => 'csv', 'group' => 'products']))
            ->streamedContent();
        $this->assertStringContainsString('Product,SKU,Currency,"Units sold",Revenue', $products);
    }
}
