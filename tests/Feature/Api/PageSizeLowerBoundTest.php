<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A zero or negative per_page must never lift the REST page-size cap.
 * Laravel ignores a negative limit(), so an unclamped value returned the
 * whole table in one response.
 */
class PageSizeLowerBoundTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $organization;

    protected Product $product;

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

        $role = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            ['name' => 'Administrator', 'is_system' => true, 'permissions' => ['view_products']]
        );
        $this->admin->roles()->syncWithoutDetaching([$role->id]);

        $this->product = $this->product('PAGE-0');
    }

    private function product(string $sku, string $tracking = 'none'): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id,
            'sku' => $sku,
            'name' => "Product {$sku}",
            'price' => 10,
            'currency' => 'USD',
            'stock' => 5,
            'min_stock' => 1,
            'is_active' => true,
            'tracking_type' => $tracking,
        ]);
    }

    public function test_negative_per_page_does_not_return_every_product(): void
    {
        Sanctum::actingAs($this->admin);
        foreach (range(1, 19) as $i) {
            $this->product("PAGE-{$i}");
        }

        // An invalid size falls back to the default page, never the whole table.
        $this->getJson('/api/v1/products?per_page=-1')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 20);
    }

    public function test_valid_per_page_is_still_honoured(): void
    {
        Sanctum::actingAs($this->admin);
        $this->product('PAGE-1');
        $this->product('PAGE-2');

        $this->getJson('/api/v1/products?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);

        $this->getJson('/api/v1/products?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson('/api/v1/products?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function listEndpoints(): array
    {
        return [
            'products' => ['/api/v1/products', 'meta.per_page'],
            'orders' => ['/api/v1/orders', 'meta.per_page'],
            'purchase orders' => ['/api/v1/purchase-orders', 'meta.per_page'],
            'suppliers' => ['/api/v1/suppliers', 'meta.per_page'],
            'stock adjustments' => ['/api/v1/stock-adjustments', 'meta.per_page'],
            'stock audits' => ['/api/v1/stock-audits', 'meta.per_page'],
            'warehouses' => ['/api/v1/warehouses', 'per_page'],
            'work orders' => ['/api/v1/work-orders', 'per_page'],
            'categories' => ['/api/v1/categories', 'meta.per_page'],
            'locations' => ['/api/v1/locations', 'meta.per_page'],
            'batches' => ['/api/v1/products/{batch}/batches', 'meta.per_page'],
            'serials' => ['/api/v1/products/{serial}/serials', 'meta.per_page'],
        ];
    }

    #[DataProvider('listEndpoints')]
    public function test_every_list_endpoint_defaults_an_invalid_per_page_and_caps_a_large_one(string $path, string $perPageKey): void
    {
        Sanctum::actingAs($this->admin);

        $path = str_replace(
            ['{batch}', '{serial}'],
            [(string) $this->product('BATCH-1', 'batch')->id, (string) $this->product('SERIAL-1', 'serial')->id],
            $path
        );

        // Below 1, empty or non-numeric: the default of 15, as before the
        // cap was added (paginate() treated 0 / empty as "use the default").
        foreach (['-1', '0', '', 'abc'] as $value) {
            $this->getJson("{$path}?per_page={$value}")
                ->assertOk()
                ->assertJsonPath($perPageKey, 15);
        }

        $this->getJson("{$path}?per_page=500")->assertOk()->assertJsonPath($perPageKey, 100);
        $this->getJson("{$path}?per_page=7")->assertOk()->assertJsonPath($perPageKey, 7);
    }
}
