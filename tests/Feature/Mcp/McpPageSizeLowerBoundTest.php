<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\ListCategoriesTool;
use App\Mcp\Tools\ListLocationsTool;
use App\Mcp\Tools\ListLowStockTool;
use App\Mcp\Tools\ListOrdersTool;
use App\Mcp\Tools\ListProductsTool;
use App\Mcp\Tools\ListPurchaseOrdersTool;
use App\Mcp\Tools\ListShipmentsTool;
use App\Mcp\Tools\ListStockBalancesTool;
use App\Mcp\Tools\ListSuppliersTool;
use App\Mcp\Tools\ListWarehousesTool;
use App\Mcp\Tools\ListWorkOrdersTool;
use App\Mcp\Tools\SearchProductsTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Tool;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A zero or negative per_page / limit must never lift an MCP list tool's
 * result cap: Laravel ignores a negative limit(), which made the query
 * unbounded.
 */
class McpPageSizeLowerBoundTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Org',
            'email' => 'test@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $role = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            ['name' => 'Administrator', 'is_system' => true, 'permissions' => ['view_products', 'manage_categories']]
        );

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);
        $this->admin->roles()->syncWithoutDetaching([$role->id]);
    }

    private function product(string $name): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-'.$name,
            'name' => $name,
            'price' => 10,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 5,
            'is_active' => true,
            'tracking_type' => 'none',
        ]);
    }

    public function test_list_products_defaults_an_invalid_per_page(): void
    {
        foreach (range(1, 16) as $i) {
            $this->product(sprintf('Item %02d', $i));
        }

        // Below 1 or non-numeric: the default page of 15, never everything.
        foreach ([-1, 0, 'abc'] as $value) {
            InventorosServer::actingAs($this->admin)
                ->tool(ListProductsTool::class, ['per_page' => $value, 'sort_by' => 'name', 'sort_dir' => 'asc'])
                ->assertOk()
                ->assertSee(['"per_page":15,', '"total":16', 'Item 15'])
                ->assertDontSee('Item 16');
        }

        InventorosServer::actingAs($this->admin)
            ->tool(ListProductsTool::class, ['per_page' => 1000])
            ->assertOk()
            ->assertSee('"per_page":100,');
    }

    public function test_list_products_still_honours_a_valid_per_page(): void
    {
        foreach (['Alpha', 'Bravo', 'Charlie'] as $name) {
            $this->product($name);
        }

        InventorosServer::actingAs($this->admin)
            ->tool(ListProductsTool::class, ['per_page' => 2, 'sort_by' => 'name', 'sort_dir' => 'asc'])
            ->assertOk()
            ->assertSee(['"per_page":2,', 'Alpha', 'Bravo'])
            ->assertDontSee('Charlie');
    }

    public function test_list_categories_defaults_an_invalid_limit_and_caps_a_large_one(): void
    {
        foreach (['Cat A', 'Cat B', 'Cat C'] as $name) {
            ProductCategory::create([
                'organization_id' => $this->organization->id,
                'name' => $name,
                'slug' => str($name)->slug()->toString(),
                'is_active' => true,
            ]);
        }

        $limits = [];
        DB::listen(function (QueryExecuted $event) use (&$limits): void {
            // Identifier quoting differs by driver (" on SQLite/PostgreSQL, ` on MySQL).
            if (preg_match('/from [`"]?product_categories[`"]?/i', $event->sql) && preg_match('/limit (\d+)/i', $event->sql, $m)) {
                $limits[] = (int) $m[1];
            }
        });

        foreach ([-1, 0, 'abc', 9999, 2] as $value) {
            InventorosServer::actingAs($this->admin)
                ->tool(ListCategoriesTool::class, ['limit' => $value])
                ->assertOk();
        }

        // Default 100 for invalid values, capped at 500, valid values kept.
        $this->assertSame([100, 100, 100, 500, 2], $limits);
    }

    /**
     * @return array<string, array{0: class-string<Tool>, 1: string}>
     */
    public static function pagedTools(): array
    {
        return [
            'list_products' => [ListProductsTool::class, 'per_page'],
            'list_orders' => [ListOrdersTool::class, 'per_page'],
            'list_purchase_orders' => [ListPurchaseOrdersTool::class, 'per_page'],
            'list_work_orders' => [ListWorkOrdersTool::class, 'per_page'],
            'list_shipments' => [ListShipmentsTool::class, 'per_page'],
            'list_stock_balances' => [ListStockBalancesTool::class, 'per_page'],
            'list_categories' => [ListCategoriesTool::class, 'limit'],
            'list_locations' => [ListLocationsTool::class, 'limit'],
            'list_warehouses' => [ListWarehousesTool::class, 'limit'],
            'list_low_stock' => [ListLowStockTool::class, 'limit'],
            'list_suppliers' => [ListSuppliersTool::class, 'limit'],
            'search_products' => [SearchProductsTool::class, 'limit'],
        ];
    }

    /**
     * @param  class-string<Tool>  $tool
     */
    #[DataProvider('pagedTools')]
    public function test_page_size_schema_declares_a_minimum_of_one(string $tool, string $argument): void
    {
        $schema = app($tool)->toArray()['inputSchema'];

        $this->assertSame(1, $schema['properties'][$argument]['minimum'] ?? null, "{$tool} must declare {$argument} minimum 1");
        $this->assertArrayHasKey('maximum', $schema['properties'][$argument], "{$tool} must declare a {$argument} maximum");
    }
}
