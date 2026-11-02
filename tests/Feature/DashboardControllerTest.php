<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => [],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    public function test_authenticated_user_can_view_dashboard(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('dashboard'));

        $response->assertStatus(200);
    }

    /**
     * Orders on the same day came back in any order: order_date is a day,
     * so the newest order of the day was not always first.
     */
    public function test_recent_orders_are_newest_first_within_a_day(): void
    {
        $ids = [];
        foreach (['R-1', 'R-2', 'R-3'] as $number) {
            $ids[] = Order::create([
                'organization_id' => $this->organization->id, 'order_number' => $number, 'status' => 'pending',
                'customer_name' => 'C', 'order_date' => now()->toDateString(), 'subtotal' => 1, 'total' => 1, 'currency' => 'USD',
            ])->id;
        }

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('recentOrders.0.id', $ids[2])
                ->where('recentOrders.1.id', $ids[1])
                ->where('recentOrders.2.id', $ids[0])
                ->etc());
    }

    /**
     * Recent orders are the ones most recently placed in the app: an order
     * backdated to last week (or imported with an old order date) that was
     * entered just now comes first, and each row still shows its order date.
     */
    public function test_recent_orders_follow_when_they_were_created_not_the_order_date(): void
    {
        $make = fn (string $number, string $orderDate, string $createdAt) => tap(Order::create([
            'organization_id' => $this->organization->id, 'order_number' => $number, 'status' => 'pending',
            'customer_name' => 'C', 'order_date' => $orderDate, 'subtotal' => 1, 'total' => 1, 'currency' => 'USD',
        ]), fn ($order) => $order->forceFill(['created_at' => $createdAt])->saveQuietly());

        $older = $make('OLDER', '2026-09-29', '2026-09-29 09:00:00');
        $backdated = $make('BACKDATED', '2026-09-20', '2026-09-30 15:00:00');
        $midday = $make('MIDDAY', '2026-09-30 12:00:00', '2026-09-30 12:00:00');

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('recentOrders.0.order_number', 'BACKDATED')
                ->where('recentOrders.1.order_number', 'MIDDAY')
                ->where('recentOrders.2.order_number', 'OLDER')
                ->has('recentOrders.0.order_date')
                ->etc());
    }

    public function test_dashboard_renders_when_an_activity_log_has_no_user(): void
    {
        // A system action, or one whose acting user was since deleted, has a
        // null user relation. The activity feed must not 500 the dashboard.
        ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => null,
            'subject_type' => Organization::class,
            'subject_id' => $this->organization->id,
            'action' => 'system_action',
            'description' => 'A system action with no user',
        ]);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertStatus(200);
    }

    public function test_dashboard_amounts_use_the_organization_currency(): void
    {
        $this->organization->forceFill(['currency' => 'CAD'])->save();

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('currency', 'CAD'));
    }

    public function test_guest_cannot_view_dashboard(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_category_breakdown_reconciles_to_active_inventory_including_uncategorized_stock(): void
    {
        $category = ProductCategory::create([
            'organization_id' => $this->organization->id, 'name' => 'Tools', 'slug' => 'tools',
        ]);
        $otherOrg = Organization::create(['name' => 'Other', 'email' => 'other-breakdown@test.com']);
        $foreignCategory = ProductCategory::create([
            'organization_id' => $otherOrg->id, 'name' => 'Private category', 'slug' => 'private',
        ]);
        $make = fn (string $sku, array $attributes = []) => Product::create($attributes + [
            'organization_id' => $this->organization->id, 'sku' => $sku, 'name' => $sku,
            'price' => 10, 'currency' => 'USD', 'stock' => 3, 'min_stock' => 0, 'is_active' => true,
        ]);
        $make('CATEGORIZED', ['category_id' => $category->id, 'price' => 100, 'stock' => 2]);
        $make('UNCATEGORIZED');
        $make('LEGACY-CATEGORY', ['category_id' => $foreignCategory->id, 'price' => 5, 'stock' => 2]);
        $make('CAD', ['currency' => 'CAD', 'price' => 50, 'stock' => 4]);
        $make('INACTIVE', ['category_id' => $category->id, 'is_active' => false, 'price' => 500]);
        $make('DELETED', ['category_id' => $category->id, 'price' => 800])->delete();
        $make('OTHER-ORG', ['organization_id' => $otherOrg->id, 'category_id' => $category->id, 'price' => 999]);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.totalValue', 240)
                ->where('stockByCategory', function ($rows) {
                    $rows = collect($rows);
                    $uncategorized = $rows->firstWhere('id', null);

                    return $rows->count() === 2
                        && (float) $rows->sum('value') === 240.0
                        && $rows->sum('count') === 4
                        && ! $rows->contains('name', 'Private category')
                        && $uncategorized['name'] === null
                        && (float) $uncategorized['value'] === 40.0
                        && $uncategorized['values'] === [
                            ['currency' => 'USD', 'amount' => 40],
                            ['currency' => 'CAD', 'amount' => 200],
                        ];
                })
            );
    }

    public function test_dashboard_stats_match_aggregate_truth(): void
    {
        // Three products: one fully stocked active, one low-stock active,
        // one inactive (should be counted in totalProducts but not totalValue).
        Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'AGG-1', 'name' => 'A',
            'price' => 10, 'currency' => 'USD',
            'stock' => 100, 'min_stock' => 10,
            'is_active' => true,
        ]);
        Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'AGG-2', 'name' => 'B',
            'price' => 5, 'currency' => 'USD',
            'stock' => 3, 'min_stock' => 10,
            'is_active' => true,
        ]);
        Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'AGG-3', 'name' => 'C',
            'price' => 50, 'currency' => 'USD',
            'stock' => 20, 'min_stock' => 0,
            'is_active' => false,
        ]);

        // Two orders: one this month (revenue), one last month (ignored).
        Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-T-1',
            'source' => 'manual',
            'customer_name' => 'X',
            'status' => 'pending',
            'subtotal' => 100, 'tax' => 0, 'shipping' => 0, 'total' => 100,
            'currency' => 'USD',
            'order_date' => now(),
        ]);
        Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-T-2',
            'source' => 'manual',
            'customer_name' => 'Y',
            'status' => 'shipped',
            'subtotal' => 200, 'tax' => 0, 'shipping' => 0, 'total' => 200,
            'currency' => 'USD',
            'order_date' => now()->subMonths(2)->startOfMonth()->addDay(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('dashboard'));
        $response->assertStatus(200);

        $response->assertInertia(fn ($page) => $page
            ->where('stats.totalProducts', 3)
            ->where('stats.lowStockProducts', 1)
            // Active stock value: 10*100 + 5*3 = 1015. Inactive C excluded.
            // Active stock value: 10*100 + 5*3 = 1015. JSON-encoded as int.
            ->where('stats.totalValue', 1015)
            ->where('stats.totalOrders', 2)
            ->where('stats.pendingOrders', 1)
            // Only this-month order counts: 100. JSON-encoded as int.
            ->where('stats.revenueThisMonth', 100)
        );
    }

    /**
     * A product sold by variant keeps its stock on the variants, so the
     * Recent products widget read products.stock and showed "Qty 0" for it
     * (#243). It now shows the summed stock like the rest of the app.
     */
    public function test_recent_products_show_the_summed_stock_of_a_variant_product(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEE', 'name' => 'T-shirt',
            'price' => 20, 'currency' => 'USD',
            'stock' => 0, 'min_stock' => 0,
            'is_active' => true, 'has_variants' => true,
        ]);
        foreach (['S' => 4, 'M' => 7] as $size => $stock) {
            ProductVariant::create([
                'product_id' => $product->id, 'organization_id' => $this->organization->id, 'sku' => "TEE-{$size}",
                'option_values' => ['Size' => $size], 'stock' => $stock, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
            ]);
        }
        Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'MUG', 'name' => 'Mug',
            'price' => 8, 'currency' => 'USD',
            'stock' => 5, 'min_stock' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('recentProducts', fn ($rows) => collect($rows)->pluck('effective_stock', 'sku')->sortKeys()->all() === ['MUG' => 5, 'TEE' => 11])
            );
    }
}
