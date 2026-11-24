<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockTransfer;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The figures behind the dashboard's revenue trend, stock movement chart and
 * product thumbnails: each comes from stored records, never a placeholder.
 */
class DashboardTrendsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->organization = Organization::create([
            'name' => 'Trends', 'email' => 'trends@example.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@trends.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);
        $role = Role::firstOrCreate(['slug' => 'system-administrator'], ['name' => 'Administrator', 'is_system' => true, 'permissions' => []]);
        $this->admin->roles()->syncWithoutDetaching([$role->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $number, string $date, float $total, string $status = 'delivered'): void
    {
        Order::create([
            'organization_id' => $this->organization->id, 'order_number' => $number, 'status' => $status,
            'customer_name' => 'C', 'order_date' => $date, 'subtotal' => $total, 'total' => $total, 'currency' => 'USD',
        ]);
    }

    private function product(string $sku, array $attributes = []): Product
    {
        return Product::create($attributes + [
            'organization_id' => $this->organization->id, 'sku' => $sku, 'name' => "Product {$sku}",
            'price' => 10, 'currency' => 'USD', 'stock' => 1, 'min_stock' => 5, 'is_active' => true,
        ]);
    }

    private function movement(Product $product, int $quantity, string $type, string $at, ?string $referenceType = null): void
    {
        $row = StockAdjustment::create([
            'organization_id' => $this->organization->id, 'product_id' => $product->id, 'user_id' => $this->admin->id,
            'type' => $type, 'quantity_before' => 0, 'quantity_after' => 0, 'adjustment_quantity' => $quantity,
            'reference_type' => $referenceType,
        ]);
        $row->forceFill(['created_at' => $at])->saveQuietly();
    }

    public function test_revenue_is_compared_with_the_same_days_of_last_month(): void
    {
        Carbon::setTestNow('2026-11-23 15:00:00');

        $this->order('NOV', '2026-11-05', 300);
        // Dated later this month: in the month's total, not in the comparison.
        $this->order('NOV-LATER', '2026-11-28', 5000);
        $this->order('OCT-IN', '2026-10-10', 120);
        $this->order('OCT-EDGE', '2026-10-23', 80);
        // After the 23rd of last month, so outside the comparable days.
        $this->order('OCT-LATE', '2026-10-24', 999);
        // Cancelled orders are not sales, as for this month's figure.
        $this->order('OCT-CANCELLED', '2026-10-12', 500, 'cancelled');

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('stats.revenueThisMonth', fn ($value) => (float) $value === 5300.0)
                ->where('stats.revenueMonthToDate', fn ($value) => (float) $value === 300.0)
                ->where('stats.revenueSamePeriodLastMonth', fn ($value) => (float) $value === 200.0));
    }

    public function test_the_comparison_stops_at_the_end_of_a_shorter_month(): void
    {
        // The 31st of March compares with all of February, not early March.
        Carbon::setTestNow('2027-03-31 09:00:00');

        $this->order('FEB-END', '2027-02-28', 40);
        $this->order('MAR-START', '2027-03-01', 70);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('stats.revenueSamePeriodLastMonth', fn ($value) => (float) $value === 40.0));
    }

    public function test_stock_movement_has_every_day_split_into_in_and_out_without_internal_moves(): void
    {
        Carbon::setTestNow('2026-11-23 15:00:00');
        $product = $this->product('MOVE');

        $this->movement($product, 12, 'manual', '2026-11-23 08:00:00');
        $this->movement($product, -5, 'order_fulfillment', '2026-11-23 10:00:00');
        $this->movement($product, -3, 'order_fulfillment', '2026-11-20 10:00:00');
        // A move between locations is recorded with no quantity; kit assembly
        // and opening balances add and remove nothing for the business.
        $this->movement($product, 0, 'transfer', '2026-11-22 10:00:00', StockTransfer::class);
        $this->movement($product, -30, 'assembly_consumption', '2026-11-22 10:00:00');
        $this->movement($product, 10, 'assembly_production', '2026-11-22 10:00:00');
        $this->movement($product, 500, 'opening_stock', '2026-11-22 10:00:00');
        // A "transfer" typed adjustment posted through the API does change stock.
        $this->movement($product, -4, 'transfer', '2026-11-21 10:00:00');
        // Older than the 30-day window.
        $this->movement($product, 99, 'manual', '2026-10-20 10:00:00');

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->has('stockMovements', 30)
                ->where('stockMovements.0.date', '2026-10-25')
                ->where('stockMovements.29', ['date' => '2026-11-23', 'in' => 12, 'out' => 5, 'total' => 7])
                ->where('stockMovements.26', ['date' => '2026-11-20', 'in' => 0, 'out' => 3, 'total' => -3])
                ->where('stockMovements.28', ['date' => '2026-11-22', 'in' => 0, 'out' => 0, 'total' => 0])
                ->where('stockMovements.27', ['date' => '2026-11-21', 'in' => 0, 'out' => 4, 'total' => -4]));
    }

    public function test_stock_movement_needs_manage_stock_like_the_ledger_it_summarises(): void
    {
        $this->movement($this->product('LEDGER'), 3, 'manual', now()->toDateTimeString());
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@trends.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'member',
        ]);
        $role = Role::create(['name' => 'Catalog viewer', 'slug' => 'catalog-viewer', 'permissions' => ['view_products']]);
        $viewer->roles()->attach($role->id);

        $this->actingAs($viewer)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('stockMovements', [])
                ->where('widgetPreferences.stock_movements', false));
    }

    public function test_low_stock_and_recent_products_carry_their_image_urls(): void
    {
        $withPhoto = $this->product('PHOTO', ['images' => ['products/photo.jpg']]);
        $this->product('PLAIN');

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('lowStockProducts', function ($rows) use ($withPhoto) {
                    $bySku = collect($rows)->keyBy('sku');

                    return str_contains($bySku['PHOTO']['image_urls'][0], "/products/{$withPhoto->id}/images/0")
                        && $bySku['PLAIN']['image_urls'] === [];
                })
                ->where('recentProducts', fn ($rows) => collect($rows)->every(fn ($row) => array_key_exists('image_urls', $row))));
    }
}
