<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dashboard's dead-stock figure is a money aggregate from the dead stock
 * report, so it follows that report's permissions (view_reports +
 * view_products + view_orders). A user without them gets no key at all, not
 * a zero.
 */
class DashboardDeadStockTileTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);

        // Stocked for a year, never sold: 6 units x cost 5 = 30 tied up.
        DB::table('products')->insert([
            'organization_id' => $this->org->id, 'sku' => 'IDLE', 'name' => 'Idle', 'price' => 9, 'purchase_price' => 5,
            'currency' => 'USD', 'stock' => 6, 'min_stock' => 0, 'is_active' => true,
            'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);

        $other = Organization::create(['name' => 'Other', 'email' => 'x@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        DB::table('products')->insert([
            'organization_id' => $other->id, 'sku' => 'THEIRS', 'name' => 'Theirs', 'price' => 9, 'purchase_price' => 500,
            'currency' => 'USD', 'stock' => 6, 'min_stock' => 0, 'is_active' => true,
            'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);
    }

    /** @param array<int, string> $permissions */
    private function userWith(array $permissions): User
    {
        static $n = 0;
        $n++;
        $user = User::create([
            'name' => "U{$n}", 'email' => "u{$n}@org.test", 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $role = Role::create(['name' => "R{$n}", 'slug' => "r-dash-{$n}", 'is_system' => false, 'permissions' => $permissions]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    public function test_a_reports_viewer_sees_the_org_dead_stock_value(): void
    {
        $this->actingAs($this->userWith(['view_reports', 'view_products', 'view_orders']))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.deadStockValue', 30)
                ->where('stats.deadStockCount', 1)
                ->etc()
            );
    }

    /** @return array<string, array{0: array<int, string>}> */
    public static function withheld(): array
    {
        return [
            'no view_reports' => [['view_products', 'view_orders']],
            'no view_orders' => [['view_reports', 'view_products']],
            'no view_products' => [['view_reports', 'view_orders']],
        ];
    }

    /** @param array<int, string> $permissions */
    #[DataProvider('withheld')]
    public function test_the_figure_is_absent_without_the_reports_permissions(array $permissions): void
    {
        $this->actingAs($this->userWith($permissions))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('stats.deadStockValue')
                ->missing('stats.deadStockCount')
                ->etc()
            );
    }
}
