<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exports\ProductsExport;
use App\Imports\ProductsImport;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as Format;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Portal\BuildsPortalFixtures;
use Tests\TestCase;

final class ProductSpreadsheetRoundTripTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    private function readBack($org, string $format): ProductsImport
    {
        $file = tempnam(sys_get_temp_dir(), 'inventoros-roundtrip-');
        try {
            file_put_contents($file, Excel::raw(new ProductsExport($org->id), $format));
            if ($format === Format::XLSX) {
                $book = IOFactory::createReader('Xlsx')->load($file);
                $this->assertSame(DataType::TYPE_NUMERIC, $book->getActiveSheet()->getCell('H2')->getDataType());
                $this->assertSame(0, $book->getActiveSheet()->getCell('K2')->getValue());
                foreach ($book->getActiveSheet()->getRowIterator(2) as $row) {
                    foreach ($row->getCellIterator() as $cell) {
                        $this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType());
                    }
                }
                $book->disconnectWorksheets();
            }
            $import = new ProductsImport($org->id);
            Excel::import($import, $file, null, $format);

            return $import;
        } finally {
            unlink($file);
        }
    }

    public static function formats(): array
    {
        return [[Format::CSV], [Format::XLSX]];
    }

    #[DataProvider('formats')]
    public function test_export_reimport_preserves_distinct_skus_and_text_without_creating_duplicates(string $format): void
    {
        $org = $this->makeOrganization('Round trip');
        $skus = ['@PART-1', "'@PART-1", "''PART-1", '=1+1', '+SKU', '-SKU', "\tTABPART", "\rCRPART", '00123', '1E3', '1.00', "'1E3", 'PLAIN-SKU', 'X","=1+1', "\nLFPART", "\u{FF1D}2+2", "\u{FF0B}WIDE", "\u{FF0D}WIDE", "\u{FF20}WIDE", '@'.str_repeat('X', 254)];
        $products = [];
        foreach ($skus as $index => $sku) {
            $product = $this->makeProduct($org, $sku);
            $product->forceFill(['name' => $index % 2 === 0 ? '@Maker' : '123',
                'barcode' => '1.00', 'description' => '=HYPERLINK("https://example.test")',
                'notes' => "'Literal note", 'stock' => 0])->save();
            $products[$product->id] = $product->fresh()->only(['sku', 'name', 'barcode', 'description', 'notes']);
        }
        $import = $this->readBack($org, $format);
        $this->assertSame([], $import->getStats()['errors']);
        $this->assertSame(0, $import->getStats()['imported'], json_encode(Product::orderBy('id')->pluck('sku')->all()));
        $this->assertSame(count($skus), $import->getStats()['updated']);
        $this->assertSame(count($skus), Product::count());
        foreach ($products as $id => $original) {
            $this->assertSame($original, Product::findOrFail($id)->only(array_keys($original)));
        }
    }

    public function test_relation_identifiers_and_supplier_skus_round_trip_as_text(): void
    {
        $org = $this->makeOrganization('Relations round trip');
        $category = ProductCategory::create(['organization_id' => $org->id, 'name' => '@Hardware', 'slug' => 'hardware']);
        $location = ProductLocation::create(['organization_id' => $org->id, 'name' => '=Room', 'code' => 'ROOM']);
        $supplier = Supplier::create(['organization_id' => $org->id, 'name' => "'Supplier", 'code' => '@SUP', 'is_active' => true]);
        $product = $this->makeProduct($org, '@RELATION');
        $product->forceFill(['category_id' => $category->id, 'location_id' => $location->id, 'stock' => 0])->save();
        $product->suppliers()->attach($supplier->id, ['is_primary' => true, 'supplier_sku' => "\tVendor", 'cost_price' => '1.25']);
        $import = $this->readBack($org, Format::CSV);
        $this->assertSame([], $import->getStats()['errors']);
        $this->assertSame([], $import->getStats()['warnings']);
        $product->refresh();
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame($location->id, $product->location_id);
        $this->assertSame(1, ProductCategory::count());
        $this->assertSame(1, ProductLocation::count());
        $this->assertSame("\tVendor", $product->primarySupplier()->pivot->supplier_sku);
    }

    public function test_unmarked_literal_apostrophe_sku_is_not_blindly_unescaped(): void
    {
        $org = $this->makeOrganization('Unmarked literal');
        $import = new ProductsImport($org->id);
        $import->collection(collect([collect(['sku' => "'@Literal", 'name' => 'Literal SKU', 'price' => 10, 'stock' => 0])]));
        $this->assertSame([], $import->getStats()['errors']);
        $this->assertSame("'@Literal", Product::sole()->sku);
    }

    public function test_ambiguous_old_escaped_sku_is_reported_instead_of_creating_a_duplicate(): void
    {
        $org = $this->makeOrganization('Old export');
        $product = $this->makeProduct($org, '@Existing');
        $import = new ProductsImport($org->id);
        $import->collection(collect([collect(['sku' => "'@Existing", 'name' => 'Old exported row', 'price' => 10, 'stock' => 0])]));
        $this->assertCount(1, $import->getStats()['errors']);
        $this->assertSame(1, Product::count());
        $this->assertSame('@Existing', $product->fresh()->sku);
    }

    public function test_unknown_encoding_is_a_row_error_and_does_not_block_later_rows(): void
    {
        $org = $this->makeOrganization('Encoding errors');
        $import = new ProductsImport($org->id);
        $import->collection(collect([
            collect(['sku' => 'BAD', 'name' => 'Unknown format', 'price' => 10, 'stock' => 0, 'inventoros_text_encoding' => 'future-format']),
            collect(['sku' => 'GOOD', 'name' => 'Ordinary import', 'price' => 10, 'stock' => 0]),
        ]));
        $this->assertCount(1, $import->getStats()['errors']);
        $this->assertSame(2, $import->getStats()['errors'][0]['row']);
        $this->assertSame('GOOD', Product::sole()->sku);
    }
}
