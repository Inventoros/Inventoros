<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exports\ProductsExport;
use App\Imports\ProductsImport;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The product CSV carries one price column per additional currency the
 * organization uses (price_EUR, price_GBP, ...), mapped to price_in_currencies.
 */
final class ProductsCurrencyColumnsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->org->id, 'sku' => 'CUR-1', 'name' => 'Priced',
            'price' => 10, 'currency' => 'USD', 'stock' => 1, 'min_stock' => 0,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(array $row): ProductsImport
    {
        // Imports always run as a user (the web form and the queued job pass
        // one); the stock recount a row books is attributed to them. Each
        // row is all-or-nothing, so without one the row would not save.
        $importer = User::firstOrCreate(
            ['email' => 'importer@org.com'],
            ['name' => 'Importer', 'password' => bcrypt('x'), 'organization_id' => $this->org->id, 'role' => 'admin'],
        );
        $import = new ProductsImport($this->org->id, $importer);
        $import->collection(new Collection([collect(array_merge(['sku' => 'CUR-1', 'name' => 'Priced', 'price' => 10, 'stock' => 5], $row))]));

        return $import;
    }

    public function test_export_has_a_price_column_per_currency_the_organization_uses(): void
    {
        $this->product(['price_in_currencies' => ['EUR' => 9.5, 'GBP' => 8]]);
        $this->product(['sku' => 'CUR-2', 'price_in_currencies' => null]);

        $export = new ProductsExport($this->org->id);
        $headings = $export->headings();

        $this->assertContains('Price EUR', $headings);
        $this->assertContains('Price GBP', $headings);
        $this->assertNotContains('Price JPY', $headings);

        $rows = $export->query()->get()->keyBy('sku')->map(fn ($p) => array_combine($headings, $export->map($p)));
        $this->assertEquals(9.5, $rows['CUR-1']['Price EUR']);
        $this->assertEquals(8, $rows['CUR-1']['Price GBP']);
        $this->assertSame('', $rows['CUR-2']['Price EUR']);
    }

    public function test_export_ignores_another_organizations_currencies(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'x@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->product(['organization_id' => $other->id, 'price_in_currencies' => ['JPY' => 1000]]);
        $this->product(['sku' => 'CUR-2']);

        $this->assertNotContains('Price JPY', (new ProductsExport($this->org->id))->headings());
    }

    public function test_import_sets_prices_from_currency_columns(): void
    {
        $import = $this->import(['price_eur' => '9.50', 'price_gbp' => 8]);

        $this->assertSame([], $import->getStats()['errors']);
        $this->assertEquals(['EUR' => 9.5, 'GBP' => 8.0], Product::where('sku', 'CUR-1')->sole()->price_in_currencies);
    }

    public function test_import_rejects_a_non_numeric_currency_price(): void
    {
        $import = $this->import(['price_eur' => 'cheap']);

        $stats = $import->getStats();
        $this->assertSame(0, $stats['imported']);
        $this->assertCount(1, $stats['errors']);
        $this->assertStringContainsString('price eur', strtolower(implode(' ', $stats['errors'][0]['errors'])));
    }

    public function test_import_rejects_a_negative_currency_price(): void
    {
        $import = $this->import(['price_gbp' => '-1']);

        $this->assertCount(1, $import->getStats()['errors']);
    }

    public function test_a_blank_currency_column_clears_that_currency_and_a_missing_column_keeps_it(): void
    {
        $this->product(['price_in_currencies' => ['EUR' => 9.5, 'GBP' => 8]]);

        $this->import(['price_eur' => '']);

        $this->assertEquals(['GBP' => 8], Product::where('sku', 'CUR-1')->sole()->price_in_currencies);
    }

    public function test_an_unknown_currency_column_is_a_warning_not_an_error(): void
    {
        $import = $this->import(['price_xyz' => '3']);

        $stats = $import->getStats();
        $this->assertSame(1, $stats['imported']);
        $this->assertSame([], $stats['errors']);
        $this->assertStringContainsString('price_xyz', $stats['warnings'][0]['warnings'][0]);
        $this->assertEmpty(Product::where('sku', 'CUR-1')->sole()->price_in_currencies);
    }

    public function test_export_then_import_round_trips_currency_prices(): void
    {
        $this->product(['price_in_currencies' => ['EUR' => 9.5]]);

        $export = new ProductsExport($this->org->id);
        $row = array_combine($export->headings(), $export->map($export->query()->sole()));
        $this->assertEquals(9.5, $row['Price EUR']);

        Product::query()->update(['price_in_currencies' => null]);
        $this->import(['price_eur' => $row['Price EUR']]);

        $this->assertEquals(['EUR' => 9.5], Product::where('sku', 'CUR-1')->sole()->price_in_currencies);
    }

    public function test_the_template_lists_a_price_column_per_used_currency(): void
    {
        $this->product(['price_in_currencies' => ['EUR' => 9.5]]);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $csv = $this->actingAs($admin)->get(route('import-export.download-template'))->streamedContent();
        $lines = preg_split('/\r?\n/', trim($csv));
        $header = str_getcsv($lines[0], escape: '');
        $example = str_getcsv($lines[1], escape: '');

        $this->assertContains('price_EUR', $header);
        $this->assertCount(count($header), $example);
    }
}
