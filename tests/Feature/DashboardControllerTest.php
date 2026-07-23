<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
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

    public function test_guest_cannot_view_dashboard(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
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
}
