<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exports\ProductsExport;
use App\Imports\ProductsImport;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\SupplierPriceHistory;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The product CSV carries the primary supplier: supplier_code / supplier_name,
 * supplier_sku and supplier_cost.
 */
final class ProductsSupplierImportExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Supplier $acme;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->acme = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Acme Parts', 'code' => 'ACME', 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(array $row): ProductsImport
    {
        $import = new ProductsImport($this->org->id);
        $import->collection(new Collection([collect(array_merge(['sku' => 'IMP-1', 'name' => 'Imported', 'price' => 10, 'stock' => 5], $row))]));

        return $import;
    }

    public function test_import_links_the_primary_supplier_by_code(): void
    {
        $import = $this->import(['supplier_code' => 'ACME', 'supplier_sku' => 'AC-77', 'supplier_cost' => '4.40']);

        $this->assertSame([], $import->getStats()['errors']);
        $this->assertSame([], $import->getStats()['warnings']);

        $supplier = Product::where('sku', 'IMP-1')->sole()->suppliers()->sole();
        $this->assertSame($this->acme->id, $supplier->id);
        $this->assertTrue($supplier->pivot->is_primary);
        $this->assertSame('AC-77', $supplier->pivot->supplier_sku);
        $this->assertEquals(4.4, (float) $supplier->pivot->cost_price);
        $this->assertSame(1, SupplierPriceHistory::count());
    }

    public function test_import_falls_back_to_the_supplier_name_case_insensitively(): void
    {
        $this->import(['supplier_name' => 'acme parts', 'supplier_cost' => 3]);

        $this->assertSame($this->acme->id, Product::where('sku', 'IMP-1')->sole()->primarySupplier()?->id);
    }

    public function test_an_unknown_supplier_warns_and_still_imports_the_product(): void
    {
        $import = $this->import(['supplier_code' => 'NOPE', 'supplier_cost' => 3]);

        $stats = $import->getStats();
        $this->assertSame(1, $stats['imported']);
        $this->assertSame([], $stats['errors']);
        $this->assertCount(1, $stats['warnings']);
        $this->assertSame(2, $stats['warnings'][0]['row']);
        $this->assertStringContainsString('NOPE', $stats['warnings'][0]['warnings'][0]);
        $this->assertCount(0, Product::where('sku', 'IMP-1')->sole()->suppliers()->get());
    }

    public function test_a_supplier_of_another_organization_is_treated_as_unknown(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'x@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        Supplier::create(['organization_id' => $other->id, 'name' => 'Foreign', 'code' => 'FOREIGN', 'is_active' => true]);

        $import = $this->import(['supplier_code' => 'FOREIGN']);

        $this->assertCount(1, $import->getStats()['warnings']);
        $this->assertCount(0, Product::where('sku', 'IMP-1')->sole()->suppliers()->get());
    }

    public function test_an_invalid_supplier_cost_warns_and_keeps_the_link_without_a_cost(): void
    {
        $import = $this->import(['supplier_code' => 'ACME', 'supplier_cost' => 'cheap']);

        $this->assertCount(1, $import->getStats()['warnings']);
        $supplier = Product::where('sku', 'IMP-1')->sole()->suppliers()->sole();
        $this->assertNull($supplier->pivot->cost_price);
    }

    public function test_reimport_switches_the_primary_supplier_and_keeps_the_old_one_linked(): void
    {
        $globex = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Globex', 'code' => 'GLOBEX', 'is_active' => true]);
        $this->import(['supplier_code' => 'ACME', 'supplier_cost' => 4]);
        $this->import(['supplier_code' => 'GLOBEX', 'supplier_cost' => 5]);

        $product = Product::where('sku', 'IMP-1')->sole();
        $this->assertSame($globex->id, $product->primarySupplier()?->id);
        $this->assertCount(2, $product->suppliers()->get());
    }

    public function test_export_includes_the_primary_supplier_columns(): void
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'EXP-1', 'name' => 'Exported',
            'price' => 10, 'currency' => 'USD', 'stock' => 1, 'min_stock' => 0,
        ]);
        $product->suppliers()->attach($this->acme->id, ['supplier_sku' => 'AC-1', 'cost_price' => 2.5, 'is_primary' => true]);

        $export = new ProductsExport($this->org->id);
        $headings = $export->headings();
        $row = array_combine($headings, $export->map($export->query()->sole()));

        $this->assertSame('ACME', $row['Supplier Code']);
        $this->assertSame('Acme Parts', $row['Supplier Name']);
        $this->assertSame('AC-1', $row['Supplier SKU']);
        $this->assertEquals(2.5, $row['Supplier Cost']);
    }

    public function test_export_leaves_supplier_columns_blank_without_a_primary_supplier(): void
    {
        Product::create([
            'organization_id' => $this->org->id, 'sku' => 'EXP-2', 'name' => 'No supplier',
            'price' => 10, 'currency' => 'USD', 'stock' => 1, 'min_stock' => 0,
        ]);

        $export = new ProductsExport($this->org->id);
        $row = array_combine($export->headings(), $export->map($export->query()->sole()));

        $this->assertSame('', $row['Supplier Code']);
        $this->assertSame('', $row['Supplier Cost']);
    }

    public function test_the_import_template_lists_the_supplier_columns(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $csv = $this->actingAs($admin)->get(route('import-export.download-template'))->streamedContent();
        $header = str_getcsv(strtok($csv, "\n"), escape: '');

        foreach (['supplier_code', 'supplier_name', 'supplier_sku', 'supplier_cost'] as $column) {
            $this->assertContains($column, $header);
        }
    }
}
