<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The dashboard computed every aggregate for every authenticated user and had
 * no permission check anywhere in it. Month revenue, total inventory
 * valuation, order counts, low stock, top products by value and value-by-
 * category all shipped in the Inertia payload regardless of role.
 *
 * Two of the shipped permission-set templates make that concrete:
 *
 *   User Administrator holds only user, role and activity-log permissions --
 *   its whole job is managing logins -- and saw the entire financial picture.
 *
 *   Warehouse Staff holds view_products and view_orders but not view_reports,
 *   and saw month revenue and total inventory valuation, which are exactly
 *   what reports.sales-analysis and reports.inventory-valuation show behind
 *   permission:view_reports.
 *
 * The widget preference flags were not a defence: the user sets those.
 */
class DashboardPermissionScopingTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Scoping Org',
            'email' => 'scoping@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SCOPE-1', 'name' => 'Widget',
            'price' => 250, 'currency' => 'USD',
            'stock' => 40, 'min_stock' => 5,
            'is_active' => true,
        ]);

        Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-SCOPE-1',
            'status' => 'pending',
            'total' => 999.99,
            'order_date' => now(),
        ]);
    }

    /** @param array<int, string> $permissions */
    protected function userWith(array $permissions, string $slug): User
    {
        $user = User::create([
            'name' => 'Scoped '.$slug,
            'email' => $slug.'@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $role = Role::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'is_system' => false,
            'permissions' => $permissions,
        ]);

        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    public function test_a_user_administrator_sees_no_financial_figures(): void
    {
        // The shipped User Administrator template, verbatim.
        $user = $this->userWith([
            'view_users', 'create_users', 'edit_users', 'delete_users',
            'view_roles', 'create_roles', 'edit_roles', 'delete_roles',
            'view_activity_log',
        ], 'user-administrator');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats', [])
                ->where('recentOrders', [])
                ->where('recentProducts', [])
                ->where('stockByCategory', [])
                ->where('topProducts', [])
                ->where('reorderSuggestions', [])
                ->etc()
            );
    }

    public function test_warehouse_staff_sees_counts_but_not_revenue_or_valuation(): void
    {
        // The shipped Warehouse Staff template: products and orders, no reports.
        $user = $this->userWith([
            'view_products', 'edit_products', 'view_orders',
            'view_purchase_orders', 'receive_purchase_orders',
        ], 'warehouse-staff');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('stats.totalProducts')
                ->has('stats.totalOrders')
                ->missing('stats.totalValue')
                ->missing('stats.revenueThisMonth')
                ->where('stockByCategory', [])
                ->etc()
            );
    }

    public function test_a_reports_viewer_sees_the_money_figures(): void
    {
        $user = $this->userWith(['view_products', 'view_orders', 'view_reports'], 'reports-viewer');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // 250 * 40 stock. JSON has no float/int distinction for a
                // whole number, so the payload carries 10000.
                ->where('stats.totalValue', 10000)
                ->where('stats.revenueThisMonth', 999.99)
                ->etc()
            );
    }

    public function test_an_order_blind_user_gets_no_order_figures(): void
    {
        $user = $this->userWith(['view_products'], 'stock-only');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('stats.totalProducts')
                ->missing('stats.totalOrders')
                ->missing('stats.pendingOrders')
                ->where('recentOrders', [])
                ->etc()
            );
    }

    /**
     * Widget preferences are a display choice the user controls, so they can
     * never be the thing keeping a figure hidden. Turning every widget on must
     * not reveal anything the permissions withhold.
     */
    public function test_enabling_every_widget_does_not_reveal_withheld_data(): void
    {
        $user = $this->userWith(['view_users'], 'nosy');
        $user->forceFill(['dashboard_widgets' => [
            'stats_overview' => true,
            'revenue_chart' => true,
            'stock_movements' => true,
            'low_stock_alerts' => true,
            'recent_orders' => true,
            'recent_products' => true,
            'top_products' => true,
            'stock_by_category' => true,
            'reorder_suggestions' => true,
        ]])->save();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats', [])
                ->where('widgetPreferences.stats_overview', false)
                ->where('widgetPreferences.recent_orders', false)
                ->where('widgetPreferences.stock_by_category', false)
                ->etc()
            );
    }

    public function test_an_admin_still_sees_everything(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@scoping.test',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('stats.totalProducts')
                ->has('stats.totalOrders')
                ->has('stats.totalValue')
                ->has('stats.revenueThisMonth')
                ->where('widgetPreferences.stats_overview', true)
                ->etc()
            );
    }
}
