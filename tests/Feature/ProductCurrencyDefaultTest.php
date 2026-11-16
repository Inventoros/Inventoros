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

    public function test_the_create_form_defaults_to_the_organizations_currency(): void
    {
        $this->org->update(['currency' => 'HUF']);

        $this->withoutVite()->actingAs($this->admin)
            ->get(route('products.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Create')
                ->where('defaultCurrency', 'HUF')
                ->has('currencies.HUF'));
    }

    public function test_the_edit_form_falls_back_to_the_organizations_currency(): void
    {
        $this->org->update(['currency' => 'HUF']);
        $product = Product::create(['organization_id' => $this->org->id, 'sku' => 'E-1', 'name' => 'P', 'price' => 1, 'stock' => 0, 'currency' => 'EUR']);

        $this->withoutVite()->actingAs($this->admin)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Edit')
                ->where('defaultCurrency', 'HUF')
                ->where('product.currency', 'EUR'));
    }

    public function test_the_create_form_offers_a_legacy_organization_currency(): void
    {
        // An organization can keep a currency that has since left the
        // supported list; the product form must still be able to select it.
        $this->org->forceFill(['currency' => 'xts'])->save();

        $this->withoutVite()->actingAs($this->admin)
            ->get(route('products.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('defaultCurrency', 'XTS')
                ->where('currencies.XTS.name', 'XTS'));
    }

    public function test_the_web_form_keeps_an_explicitly_chosen_currency(): void
    {
        $this->org->update(['currency' => 'HUF']);

        $this->actingAs($this->admin)
            ->post(route('products.store'), ['sku' => 'WEB-1', 'name' => 'Web product', 'price' => 5, 'currency' => 'EUR', 'stock' => 0, 'min_stock' => 0])
            ->assertSessionHasNoErrors();

        $this->assertSame('EUR', Product::where('sku', 'WEB-1')->sole()->currency);
    }

    public function test_graphql_creates_products_in_the_organizations_currency(): void
    {
        $this->org->update(['currency' => 'HUF']);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/graphql', ['query' => 'mutation { createProduct(sku: "GQL-1", name: "Gql product", price: 5) { id currency } }'])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.createProduct.currency', 'HUF');

        $this->postJson('/graphql', ['query' => 'mutation { createProduct(sku: "GQL-2", name: "Gql product", price: 5, currency: "EUR") { id currency } }'])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.createProduct.currency', 'EUR');
    }

    public function test_rest_and_import_use_a_huf_organization_currency_and_keep_explicit_ones(): void
    {
        $this->org->update(['currency' => 'HUF']);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/products', ['sku' => 'API-H1', 'name' => 'Api product', 'price' => 5])->assertCreated();
        $this->postJson('/api/v1/products', ['sku' => 'API-H2', 'name' => 'Api product', 'price' => 5, 'currency' => 'EUR'])->assertCreated();

        Excel::import(new ProductsImport($this->org->id), UploadedFile::fake()->createWithContent(
            'products.csv',
            "name,sku,price,currency,stock
Imported,IMP-H1,5,,0
Imported,IMP-H2,5,EUR,0
",
        ));

        $currencies = Product::withoutGlobalScopes()->whereIn('sku', ['API-H1', 'API-H2', 'IMP-H1', 'IMP-H2'])->pluck('currency', 'sku');
        $this->assertSame(['API-H1' => 'HUF', 'API-H2' => 'EUR', 'IMP-H1' => 'HUF', 'IMP-H2' => 'EUR'], $currencies->sortKeys()->all());
    }
}
