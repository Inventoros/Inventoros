<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $viewOnlyUser;

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

        $this->viewOnlyUser = User::create([
            'name' => 'View Only User',
            'email' => 'viewer@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => ['view_reports'],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'is_system' => true,
                'permissions' => [],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    public function test_admin_can_view_reports_index(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.index'));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_inventory_valuation_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.inventory-valuation'));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_stock_movement_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.stock-movement'));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_low_stock_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.low-stock'));

        $response->assertStatus(200);
    }

    public function test_stock_movement_summary_honors_the_active_filters(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id, 'sku' => 'SM-1', 'name' => 'SM',
            'price' => 1, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0,
        ]);

        // Two 'manual' increases (+5, +3) and one 'damage' decrease (-2).
        foreach ([['manual', 5], ['manual', 3], ['damage', -2]] as [$type, $qty]) {
            StockAdjustment::create([
                'organization_id' => $this->organization->id,
                'product_id' => $product->id,
                'user_id' => $this->admin->id,
                'type' => $type,
                'quantity_before' => 100,
                'quantity_after' => 100 + $qty,
                'adjustment_quantity' => $qty,
                'reason' => 'test',
            ]);
        }

        $response = $this->actingAs($this->admin)
            ->get(route('reports.stock-movement', ['type' => 'manual']));

        // Summary must reflect only the filtered (manual) rows, not the whole org.
        $response->assertInertia(fn ($page) => $page
            ->where('summary.total_adjustments', 2)
            ->where('summary.total_increases', 8)
            ->where('summary.total_decreases', 0)
        );
    }

    public function test_low_stock_reorder_cost_is_never_negative_with_null_max_stock(): void
    {
        // Low-stock product (stock <= min_stock) with no max_stock target.
        Product::create([
            'organization_id' => $this->organization->id, 'sku' => 'LS-1', 'name' => 'LS',
            'price' => 10, 'purchase_price' => 5, 'currency' => 'USD',
            'stock' => 2, 'min_stock' => 10, 'max_stock' => null, 'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('reports.low-stock'));

        // Falls back to min_stock (10) as the target: (10 - 2) * 5 = 40, never
        // a negative cost from null - stock.
        $response->assertInertia(fn ($page) => $page
            ->where('summary.total_reorder_cost', 40)
        );
    }

    public function test_user_without_permission_cannot_view_reports(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('reports.index'));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_view_reports(): void
    {
        $response = $this->get(route('reports.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_sales_analysis_aggregates_match_truth(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SR-1', 'name' => 'Widget',
            'price' => 10, 'currency' => 'USD',
            'stock' => 100, 'min_stock' => 0,
            'is_active' => true,
        ]);

        // Two orders inside the default 30-day window.
        $o1 = Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-SR-1',
            'source' => 'manual',
            'customer_name' => 'A',
            'status' => 'pending',
            'subtotal' => 50, 'tax' => 0, 'shipping' => 0, 'total' => 50,
            'currency' => 'USD',
            'order_date' => now()->subDays(2),
        ]);
        OrderItem::create([
            'order_id' => $o1->id, 'product_id' => $product->id,
            'product_name' => 'Widget', 'sku' => 'SR-1',
            'quantity' => 5, 'unit_price' => 10, 'subtotal' => 50, 'tax' => 0, 'total' => 50,
        ]);

        $o2 = Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-SR-2',
            'source' => 'manual',
            'customer_name' => 'B',
            'status' => 'shipped',
            'subtotal' => 30, 'tax' => 0, 'shipping' => 0, 'total' => 30,
            'currency' => 'USD',
            'order_date' => now()->subDay(),
        ]);
        OrderItem::create([
            'order_id' => $o2->id, 'product_id' => $product->id,
            'product_name' => 'Widget', 'sku' => 'SR-1',
            'quantity' => 3, 'unit_price' => 10, 'subtotal' => 30, 'tax' => 0, 'total' => 30,
        ]);

        // One order OUTSIDE the window — should be ignored.
        $o3 = Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-SR-3',
            'source' => 'manual',
            'customer_name' => 'Old',
            'status' => 'shipped',
            'subtotal' => 999, 'tax' => 0, 'shipping' => 0, 'total' => 999,
            'currency' => 'USD',
            'order_date' => now()->subDays(60),
        ]);
        OrderItem::create([
            'order_id' => $o3->id, 'product_id' => $product->id,
            'product_name' => 'Widget', 'sku' => 'SR-1',
            'quantity' => 99, 'unit_price' => 10, 'subtotal' => 999, 'tax' => 0, 'total' => 999,
        ]);

        $response = $this->actingAs($this->admin)->get(route('reports.sales-analysis'));
        $response->assertStatus(200);

        $response->assertInertia(fn ($page) => $page
            ->where('summary.total_orders', 2)
            ->where('summary.total_revenue', 80)
            ->where('summary.total_items_sold', 8)
            ->where('summary.average_order_value', 40)
            ->has('byStatus', 2)
            ->has('topProducts', 1)
            ->where('topProducts.0.quantity_sold', 8)
            ->where('topProducts.0.revenue', 80)
            ->has('dailySales')
        );
    }
}
