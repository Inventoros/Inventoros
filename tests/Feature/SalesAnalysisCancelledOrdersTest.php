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
 * Sales Analysis counted cancelled orders in its orders, revenue, items sold,
 * daily trend and period comparison, while the Top sellers analytics and the
 * payment position left them out, so the same period showed different sales
 * on different screens. A cancelled order is not a sale: every sales figure
 * now leaves it out. Sales by status still lists it, so cancellations stay
 * visible.
 */
class SalesAnalysisCancelledOrdersTest extends TestCase
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
    }

    private function order(string $number, string $date, float $total, int $qty, string $status, string $product): void
    {
        $id = DB::table('orders')->insertGetId([
            'organization_id' => $this->org->id, 'order_number' => $number, 'status' => $status,
            'subtotal' => $total, 'tax' => 0, 'total' => $total, 'currency' => 'USD',
            'amount_paid' => 0, 'payment_status' => 'unpaid',
            'order_date' => $date, 'created_at' => $date, 'updated_at' => $date,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $id, 'product_id' => null, 'product_name' => $product, 'sku' => $product, 'quantity' => $qty,
            'unit_price' => $total / $qty, 'subtotal' => $total, 'tax' => 0, 'total' => $total,
            'created_at' => $date, 'updated_at' => $date,
        ]);
    }

    public function test_cancelled_orders_are_left_out_of_every_sales_figure(): void
    {
        $this->order('S-1', '2026-06-15 10:00:00', 100, 2, 'delivered', 'Widget');
        $this->order('S-2', '2026-06-15 11:00:00', 500, 5, 'cancelled', 'Gadget');

        $this->actingAs($this->admin)
            ->get(route('reports.sales-analysis', ['date_from' => '2026-06-11', 'date_to' => '2026-06-20']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_orders', 1)
                ->where('summary.total_revenue', fn ($v) => (float) $v === 100.0)
                ->where('summary.total_items_sold', 2)
                ->where('summary.average_order_value', fn ($v) => (float) $v === 100.0)
                ->has('dailySales', 1)
                ->where('dailySales.0.orders', 1)
                ->where('dailySales.0.revenue', fn ($v) => (float) $v === 100.0)
                ->has('topProducts', 1)
                ->where('topProducts.0.product_name', 'Widget')
                ->where('comparison.current.total_orders', 1)
                ->where('comparison.current.total_revenue', fn ($v) => (float) $v === 100.0)
                ->where('comparison.current.total_items_sold', 2)
                // The status breakdown still shows the cancellation.
                ->where('byStatus', fn ($rows) => collect($rows)->pluck('count', 'status')->sortKeys()->all() === ['cancelled' => 1, 'delivered' => 1])
            );
    }

    public function test_the_dashboard_revenue_leaves_cancelled_orders_out(): void
    {
        $this->order('S-1', now()->toDateTimeString(), 100, 2, 'delivered', 'Widget');
        $this->order('S-2', now()->toDateTimeString(), 500, 5, 'cancelled', 'Gadget');

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('stats.revenueThisMonth', 100));
    }
}
