<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The outstanding-receivables tile is a financial figure: like revenue and
 * valuation it needs view_reports, and since it summarises payments it also
 * needs view_payments. Without both it is absent from the payload, not zero.
 */
class DashboardReceivablesTileTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'AR Org', 'email' => 'ar@organization.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $base = ['organization_id' => $this->organization->id, 'order_date' => now(), 'status' => 'pending'];
        Order::create($base + ['order_number' => 'ORD-AR-1', 'total' => 100, 'amount_paid' => 40, 'payment_status' => 'partial']);
        Order::create($base + ['order_number' => 'ORD-AR-2', 'total' => 50, 'amount_paid' => 0, 'payment_status' => 'unpaid']);
        Order::create($base + ['order_number' => 'ORD-AR-3', 'total' => 70, 'amount_paid' => 70, 'payment_status' => 'paid']);
        Order::create($base + ['order_number' => 'ORD-AR-4', 'total' => 30, 'amount_paid' => 45, 'payment_status' => 'overpaid']);
        // A cancelled order is not a receivable.
        Order::create(['status' => 'cancelled', 'order_number' => 'ORD-AR-5', 'total' => 500, 'amount_paid' => 0] + $base);

        $otherOrg = Organization::create(['name' => 'Other', 'email' => 'other-ar@organization.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        Order::create(['organization_id' => $otherOrg->id, 'order_number' => 'ORD-X-1', 'total' => 999, 'amount_paid' => 0, 'order_date' => now(), 'status' => 'pending']);
    }

    /** @param array<int, string> $permissions */
    private function userWith(array $permissions, string $slug): User
    {
        $user = User::create([
            'name' => $slug, 'email' => "{$slug}@ar.test", 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'member',
        ]);
        $role = Role::create(['name' => $slug, 'slug' => $slug, 'is_system' => false, 'permissions' => $permissions]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    public function test_the_tile_sums_what_is_still_owed(): void
    {
        $user = $this->userWith(['view_orders', 'view_reports', 'view_payments'], 'ar-full');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                // 60 + 50; paid, overpaid, cancelled and other-org orders owe nothing here.
                ->where('stats.outstandingReceivables', 110)
                ->etc()
            );
    }

    public function test_the_tile_is_absent_without_view_payments(): void
    {
        $user = $this->userWith(['view_orders', 'view_reports'], 'ar-reports-only');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('stats.revenueThisMonth')
                ->missing('stats.outstandingReceivables')
                ->etc()
            );
    }

    public function test_the_tile_is_absent_without_view_reports(): void
    {
        $user = $this->userWith(['view_orders', 'view_payments'], 'ar-payments-only');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('stats.outstandingReceivables')
                ->etc()
            );
    }

    public function test_admins_see_it(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@ar.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('stats.outstandingReceivables', 110)->etc());
    }
}
