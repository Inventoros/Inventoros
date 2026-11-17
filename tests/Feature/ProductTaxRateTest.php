<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exports\ProductsExport;
use App\Imports\ProductsImport;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreateProductTool;
use App\Mcp\Tools\GetProductTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A product's tax rate (#416): the percent it is sold at, next to its net
 * selling price, on every surface and in the product CSV.
 */
final class ProductTaxRateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Tax Org', 'email' => 'tax@org.com', 'currency' => 'HUF', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@tax.test', 'password' => bcrypt('password'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->org->id, 'sku' => 'BOOK-1', 'name' => 'Book',
            'price' => 1000, 'currency' => 'HUF', 'stock' => 5, 'min_stock' => 0, 'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function webPayload(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'WEB-1', 'name' => 'Web product', 'price' => 10, 'currency' => 'HUF',
            'stock' => 0, 'min_stock' => 0, 'is_active' => true,
        ], $overrides);
    }

    public function test_the_web_form_saves_a_tax_rate_and_keeps_the_price_net(): void
    {
        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->webPayload(['price' => 10, 'tax_rate' => 27]))
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'WEB-1')->sole();
        $this->assertSame('27.000', (string) $product->tax_rate);
        $this->assertSame('10.00', (string) $product->price);
    }

    public function test_the_rate_is_optional_and_can_be_cleared(): void
    {
        $product = $this->product(['tax_rate' => 5]);

        $this->actingAs($this->admin)
            ->put(route('products.update', $product), $this->webPayload(['sku' => 'BOOK-1', 'name' => 'Book', 'price' => 1000, 'tax_rate' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull($product->fresh()->tax_rate);
    }

    public function test_rate_validation(): void
    {
        foreach ([101, -1, 'abc', '27.1234'] as $index => $bad) {
            $this->actingAs($this->admin)
                ->post(route('products.store'), $this->webPayload(['sku' => "BAD-{$index}", 'tax_rate' => $bad]))
                ->assertSessionHasErrors('tax_rate');
        }

        foreach (['0', '5.5', '9.375', '100'] as $index => $good) {
            $this->actingAs($this->admin)
                ->post(route('products.store'), $this->webPayload(['sku' => "OK-{$index}", 'tax_rate' => $good]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame('9.375', (string) Product::where('sku', 'OK-2')->sole()->tax_rate);
        $this->assertSame('0.000', (string) Product::where('sku', 'OK-0')->sole()->tax_rate);
    }

    public function test_rest_api_reads_and_writes_the_rate(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $id = $this->postJson('/api/v1/products', ['sku' => 'API-1', 'name' => 'Api', 'price' => 100, 'tax_rate' => 27])
            ->assertCreated()
            ->assertJsonPath('data.tax_rate', '27.000')
            ->json('data.id');

        $this->putJson("/api/v1/products/{$id}", ['tax_rate' => 5])->assertOk()->assertJsonPath('data.tax_rate', '5.000');
        $this->putJson("/api/v1/products/{$id}", ['tax_rate' => 150])->assertUnprocessable()->assertJsonValidationErrors('tax_rate');
        $this->getJson("/api/v1/products/{$id}")->assertJsonPath('data.tax_rate', '5.000');
    }

    public function test_rest_api_cannot_change_another_organizations_product(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'other@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $foreign = $this->product(['organization_id' => $other->id, 'sku' => 'FOREIGN', 'tax_rate' => 5]);

        Sanctum::actingAs($this->admin, ['*']);
        $this->putJson("/api/v1/products/{$foreign->id}", ['tax_rate' => 27])->assertNotFound();

        $this->assertSame('5.000', (string) $foreign->fresh()->tax_rate);
    }

    public function test_graphql_reads_and_writes_the_rate(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/graphql', ['query' => 'mutation { createProduct(sku: "GQL-1", name: "Gql", price: 10, tax_rate: 5.5) { id tax_rate } }'])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.createProduct.tax_rate', 5.5);

        $id = Product::where('sku', 'GQL-1')->value('id');
        $this->postJson('/graphql', ['query' => sprintf('mutation { updateProduct(id: %d, tax_rate: 27) { tax_rate } }', $id)])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.updateProduct.tax_rate', 27);
    }

    public function test_mcp_tools_read_and_write_the_rate(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(CreateProductTool::class, ['sku' => 'MCP-1', 'name' => 'Mcp', 'price' => 10, 'tax_rate' => 27])
            ->assertOk()
            ->assertSee('"tax_rate":"27.000"');

        $product = Product::where('sku', 'MCP-1')->sole();
        $this->assertSame('27.000', (string) $product->tax_rate);

        InventorosServer::actingAs($this->admin)
            ->tool(GetProductTool::class, ['id' => $product->id])
            ->assertOk()
            ->assertSee('"tax_rate":"27.000"');
    }

    public function test_export_adds_the_rate_and_the_price_including_tax_after_the_existing_columns(): void
    {
        $this->product(['sku' => 'BOOK-1', 'price' => 1000, 'tax_rate' => 5]);
        $this->product(['sku' => 'PEN-1', 'price' => '10.05', 'tax_rate' => 27]);
        $this->product(['sku' => 'NONE-1', 'price' => 10]);

        $export = new ProductsExport($this->org->id);
        $headings = $export->headings();
        $rows = collect($export->query()->get())->mapWithKeys(fn (Product $p) => [$p->sku => array_combine($headings, $export->map($p))]);

        // New columns come last, before the text-encoding marker, so files
        // mapped by position keep their old columns where they were.
        $this->assertSame(['Tax Rate', 'Price Incl. Tax', 'Inventoros Text Encoding'], array_slice($headings, -3));
        $this->assertSame('Price', $headings[7]);

        $this->assertEquals(5, $rows['BOOK-1']['Tax Rate']);
        $this->assertSame('1050.00', $rows['BOOK-1']['Price Incl. Tax']);
        // 10.05 x 27% = 2.7135 -> 2.71
        $this->assertSame('12.76', $rows['PEN-1']['Price Incl. Tax']);
        $this->assertSame('', $rows['NONE-1']['Tax Rate']);
        $this->assertSame('', $rows['NONE-1']['Price Incl. Tax']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(array $row): ProductsImport
    {
        $import = new ProductsImport($this->org->id);
        $import->collection(new Collection([collect(array_merge(['sku' => 'BOOK-1', 'name' => 'Book', 'price' => 1000, 'stock' => 5], $row))]));

        return $import;
    }

    public function test_import_reads_the_rate_column(): void
    {
        $import = $this->import(['tax_rate' => '5']);

        $this->assertSame([], $import->getStats()['errors']);
        $this->assertSame('5.000', (string) Product::where('sku', 'BOOK-1')->sole()->tax_rate);
    }

    public function test_import_without_the_column_leaves_the_rate_alone_and_a_blank_cell_clears_it(): void
    {
        $product = $this->product(['tax_rate' => 27]);

        $this->import([]);
        $this->assertSame('27.000', (string) $product->fresh()->tax_rate);

        $this->import(['tax_rate' => '']);
        $this->assertNull($product->fresh()->tax_rate);
    }

    public function test_import_ignores_the_price_including_tax_column(): void
    {
        $product = $this->product(['price' => 1000, 'tax_rate' => 27]);

        $import = $this->import(['price' => 1000, 'tax_rate' => 27, 'price_incl_tax' => '9999']);

        $this->assertSame([], $import->getStats()['errors']);
        $this->assertSame('1000.00', (string) $product->fresh()->price);
    }

    public function test_import_rejects_an_invalid_rate(): void
    {
        $import = $this->import(['tax_rate' => '150']);

        $this->assertNotEmpty($import->getStats()['errors']);
        $this->assertFalse(Product::where('sku', 'BOOK-1')->exists());
    }

    public function test_the_import_template_lists_the_rate_column(): void
    {
        $csv = $this->actingAs($this->admin)->get(route('import-export.download-template'))->streamedContent();
        $lines = preg_split('/\r?\n/', trim($csv));
        $header = str_getcsv($lines[0], escape: '');
        $example = str_getcsv($lines[1], escape: '');

        $this->assertContains('tax_rate', $header);
        $this->assertCount(count($header), $example);
    }

    public function test_order_forms_receive_each_products_rate(): void
    {
        $this->product(['tax_rate' => 5]);

        $this->actingAs($this->admin)->get(route('orders.create'))
            ->assertInertia(fn ($page) => $page->where('products.0.tax_rate', '5.000'));
    }
}
