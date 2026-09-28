<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\ReceivablesAgingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Amounts in different currencies are never added together. The receivables
 * report and the dashboard money tiles show a total per currency: the
 * organization's own currency first, then any others that have amounts.
 */
class MultiCurrencyTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-27 12:00:00');
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Maple', 'email' => 'maple@org.com', 'currency' => 'CAD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@maple.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->order('CAD-1', 'CAD', 100, 40, '2026-09-27', 'Acme');
        $this->order('CAD-2', 'CAD', 50, 0, '2026-08-01', 'Bolt');
        $this->order('USD-1', 'USD', 80, 0, '2026-09-20', 'Acme');
        $this->order('EUR-1', 'eur', 20, 0, '2026-09-27', 'Cogs');
        $this->order('EUR-PAID', 'EUR', 30, 30, '2026-09-27', 'Cogs');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function order(string $number, string $currency, float $total, float $paid, string $date, string $customer): Order
    {
        return Order::create([
            'organization_id' => $this->org->id, 'order_number' => $number, 'customer_name' => $customer,
            'status' => 'pending', 'order_date' => $date, 'currency' => $currency,
            'total' => $total, 'amount_paid' => $paid,
            'payment_status' => $paid <= 0 ? 'unpaid' : ($paid < $total ? 'partial' : 'paid'),
        ]);
    }

    public function test_aging_reports_one_currency_and_lists_a_total_per_currency(): void
    {
        $report = app(ReceivablesAgingService::class)->build($this->org->id);

        // Defaults to the organization's currency: 60 + 50, not 60 + 50 + 80 + 20.
        $this->assertSame('CAD', $report['currency']);
        $this->assertSame('110.00', $report['summary']['total_outstanding']);
        $this->assertSame(2, $report['summary']['order_count']);
        // Oldest first.
        $this->assertSame(['CAD-2', 'CAD-1'], array_column($report['orders'], 'order_number'));
        $this->assertSame(['Acme', 'Bolt'], array_column($report['customers'], 'customer'));
        $this->assertSame('110.00', array_reduce($report['buckets'], fn ($sum, $b) => bcadd($sum, $b['amount'], 2), '0'));

        $this->assertSame([
            ['currency' => 'CAD', 'total_outstanding' => '110.00', 'order_count' => 2],
            ['currency' => 'EUR', 'total_outstanding' => '20.00', 'order_count' => 1],
            ['currency' => 'USD', 'total_outstanding' => '80.00', 'order_count' => 1],
        ], $report['currencies']);
    }

    public function test_aging_can_be_viewed_in_another_currency(): void
    {
        $report = app(ReceivablesAgingService::class)->build($this->org->id, currency: 'usd');

        $this->assertSame('USD', $report['currency']);
        $this->assertSame('80.00', $report['summary']['total_outstanding']);
        $this->assertSame(['USD-1'], array_column($report['orders'], 'order_number'));
        $this->assertCount(3, $report['currencies']);
    }

    public function test_the_report_page_takes_the_currency_from_the_query_string(): void
    {
        $this->actingAs($this->admin)->get(route('reports.receivables', ['currency' => 'EUR']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Receivables')
                ->where('currency', 'EUR')
                ->where('summary.total_outstanding', '20.00')
                ->has('currencies', 3)
                ->etc()
            );

        $this->actingAs($this->admin)->get(route('reports.receivables'))
            ->assertInertia(fn (Assert $page) => $page->where('currency', 'CAD')->where('summary.total_outstanding', '110.00')->etc());
    }

    public function test_the_dead_stock_tile_is_per_currency(): void
    {
        foreach ([['OLD-CAD', 'CAD', 4], ['OLD-USD', 'USD', 5]] as [$sku, $currency, $cost]) {
            $product = Product::create([
                'organization_id' => $this->org->id, 'sku' => $sku, 'name' => $sku, 'price' => 20, 'purchase_price' => $cost,
                'currency' => $currency, 'stock' => 10, 'min_stock' => 0, 'is_active' => true,
            ]);
            $product->forceFill(['created_at' => '2026-01-01 00:00:00'])->saveQuietly();
        }

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.deadStockValue', 40)
                ->where('stats.deadStockCount', 2)
                ->where('stats.byCurrency.deadStockValue', [
                    ['currency' => 'CAD', 'amount' => 40],
                    ['currency' => 'USD', 'amount' => 50],
                ])
                ->etc()
            );
    }

    public function test_dashboard_money_tiles_are_per_currency(): void
    {
        Product::create([
            'organization_id' => $this->org->id, 'sku' => 'CAD-P', 'name' => 'Local', 'price' => 10,
            'currency' => 'CAD', 'stock' => 3, 'min_stock' => 0, 'is_active' => true,
        ]);
        Product::create([
            'organization_id' => $this->org->id, 'sku' => 'USD-P', 'name' => 'Imported', 'price' => 7,
            'currency' => 'USD', 'stock' => 2, 'min_stock' => 0, 'is_active' => true,
        ]);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('currency', 'CAD')
                // The headline figure is the organization's currency only.
                ->where('stats.outstandingReceivables', 110)
                ->where('stats.revenueThisMonth', 100)
                ->where('stats.totalValue', 30)
                ->where('stats.byCurrency.outstandingReceivables', [
                    ['currency' => 'CAD', 'amount' => 110],
                    ['currency' => 'EUR', 'amount' => 20],
                    ['currency' => 'USD', 'amount' => 80],
                ])
                ->where('stats.byCurrency.revenueThisMonth', [
                    ['currency' => 'CAD', 'amount' => 100],
                    ['currency' => 'EUR', 'amount' => 50],
                    ['currency' => 'USD', 'amount' => 80],
                ])
                ->where('stats.byCurrency.totalValue', [
                    ['currency' => 'CAD', 'amount' => 30],
                    ['currency' => 'USD', 'amount' => 14],
                ])
                ->etc()
            );
    }
}
