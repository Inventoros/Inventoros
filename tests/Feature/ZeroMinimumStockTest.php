<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A minimum stock of 0 means "no minimum": an item kept at 0 with a 0
 * minimum (e.g. discontinued but still listed) is not low stock. It only
 * counts once it goes negative, i.e. oversold.
 */
class ZeroMinimumStockTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Zero Min Org', 'email' => 'zero@test.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@zero.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);

        $role = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            ['name' => 'Administrator', 'description' => 'Full access', 'is_system' => true, 'permissions' => \App\Enums\Permission::values()],
        );
        $this->admin->roles()->syncWithoutDetaching([$role->id]);
    }

    private function product(string $sku, int $stock, int $min): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id, 'sku' => $sku, 'name' => $sku,
            'price' => 10, 'currency' => 'USD', 'stock' => $stock, 'min_stock' => $min, 'is_active' => true,
        ]);
    }

    public function test_zero_stock_with_zero_minimum_is_not_low_stock(): void
    {
        $discontinued = $this->product('DISC', 0, 0);
        $empty = $this->product('EMPTY', 0, 5);
        $oversold = $this->product('OVER', -2, 0);
        $atMinimum = $this->product('ATMIN', 5, 5);
        $fine = $this->product('FINE', 3, 0);

        $low = Product::lowStock()->pluck('sku')->sort()->values()->all();

        $this->assertSame(['ATMIN', 'EMPTY', 'OVER'], $low);
        $this->assertFalse($discontinued->isLowStock());
        $this->assertFalse($fine->isLowStock());
        $this->assertTrue($empty->isLowStock());
        $this->assertTrue($oversold->isLowStock());
        $this->assertTrue($atMinimum->isLowStock());
    }

    public function test_low_stock_report_leaves_out_zero_minimum_items_at_zero(): void
    {
        $this->product('DISC', 0, 0);
        $this->product('EMPTY', 0, 5);

        $this->actingAs($this->admin)->get(route('reports.low-stock'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.total_low_stock', 1)
                ->where('summary.out_of_stock', 1)
                ->has('products', 1)
                ->where('products.0.sku', 'EMPTY')
            );
    }

    public function test_dashboard_low_stock_figures_leave_out_zero_minimum_items_at_zero(): void
    {
        $this->product('DISC', 0, 0);
        $this->product('EMPTY', 0, 5);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.lowStockProducts', 1)
                ->has('lowStockProducts', 1)
                ->where('lowStockProducts.0.sku', 'EMPTY')
            );
    }

    public function test_variant_with_zero_minimum_at_zero_is_not_low_stock(): void
    {
        $parent = $this->product('TEE', 0, 0);

        $discontinued = ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $parent->id,
            'sku' => 'TEE-S', 'title' => 'Small', 'option_values' => ['Size' => 'Small'], 'stock' => 0, 'min_stock' => 0, 'is_active' => true,
        ]);
        $empty = ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $parent->id,
            'sku' => 'TEE-M', 'title' => 'Medium', 'option_values' => ['Size' => 'Medium'], 'stock' => 0, 'min_stock' => 2, 'is_active' => true,
        ]);

        $this->assertFalse($discontinued->isLowStock());
        $this->assertTrue($empty->isLowStock());
        $this->assertSame(['TEE-M'], ProductVariant::lowStock()->pluck('sku')->all());
    }
}
