<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\ProductsImport;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreateProductTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * A product created without a currency is priced in its organization's
 * currency, not the column default (USD). Order pricing relies on this: an
 * order in the organization's currency takes a product's own price only when
 * the product is in that currency.
 */
class ProductCurrencyDefaultTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Maple', 'email' => 'maple@org.com', 'currency' => 'CAD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@maple.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
    }

    public function test_a_product_without_a_currency_takes_the_organizations(): void
    {
        $product = Product::create(['organization_id' => $this->org->id, 'sku' => 'P-1', 'name' => 'P', 'price' => 1, 'stock' => 0]);
        $this->assertSame('CAD', $product->fresh()->currency);

        $explicit = Product::create(['organization_id' => $this->org->id, 'sku' => 'P-2', 'name' => 'P', 'price' => 1, 'stock' => 0, 'currency' => 'EUR']);
        $this->assertSame('EUR', $explicit->fresh()->currency);
    }

    public function test_rest_creates_products_in_the_organizations_currency(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/products', ['sku' => 'API-1', 'name' => 'Api product', 'price' => 5])
            ->assertCreated();

        $this->assertSame('CAD', Product::where('sku', 'API-1')->sole()->currency);
    }

    public function test_mcp_creates_products_in_the_organizations_currency(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(CreateProductTool::class, ['sku' => 'MCP-1', 'name' => 'Mcp product', 'price' => 5])
            ->assertOk();

        $this->assertSame('CAD', Product::where('sku', 'MCP-1')->sole()->currency);
    }

    public function test_the_products_import_defaults_to_the_organizations_currency(): void
    {
        $import = new ProductsImport($this->org->id);
        Excel::import($import, UploadedFile::fake()->createWithContent('products.csv', "name,sku,price,stock\nImported,IMP-1,5,0\n"));

        $this->assertSame('CAD', Product::withoutGlobalScopes()->where('sku', 'IMP-1')->sole()->currency);
    }
}
