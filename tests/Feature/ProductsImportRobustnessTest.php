<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\ProductsImport;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductsImportRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create([
            'name' => 'Imp', 'email' => 'imp@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
    }

    public function test_reimporting_a_soft_deleted_sku_restores_and_updates_it(): void
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'REIMP-1', 'name' => 'Old',
            'price' => 1, 'currency' => 'USD', 'stock' => 5, 'min_stock' => 0,
        ]);
        $product->delete(); // soft delete

        // The importing user owns the recount the stock change is booked as.
        $importer = User::factory()->admin()->forOrganization($this->org->id)->create();
        $import = new ProductsImport($this->org->id, $importer);
        $import->collection(new Collection([
            collect(['sku' => 'REIMP-1', 'name' => 'Restored', 'price' => 10, 'stock' => 20]),
        ]));

        $fresh = Product::withTrashed()->where('sku', 'REIMP-1')->first();
        $this->assertNull($fresh->deleted_at, 'The soft-deleted product should be restored.');
        $this->assertSame('Restored', $fresh->name);
        $this->assertSame(20, (int) $fresh->stock);
        $this->assertSame(1, $import->getStats()['updated']);
        // No opaque unique-constraint error from trying to re-create the SKU.
        $this->assertSame([], $import->getStats()['errors']);
    }

    public function test_duplicate_sku_within_a_file_is_warned_and_skipped(): void
    {
        $import = new ProductsImport($this->org->id);
        $import->collection(new Collection([
            collect(['sku' => 'DUP-1', 'name' => 'First', 'price' => 10, 'stock' => 5]),
            collect(['sku' => 'DUP-1', 'name' => 'Second', 'price' => 20, 'stock' => 9]),
        ]));

        $stats = $import->getStats();

        // Only the first row created a product; the duplicate was warned + skipped.
        $this->assertSame(1, $stats['imported']);
        $this->assertNotEmpty($stats['warnings']);
        $this->assertSame('First', Product::where('sku', 'DUP-1')->first()->name);
    }

    public static function invalidPurchaseCosts(): array
    {
        return [['-10'], ['-0.01'], ['not-a-price'], ['NaN'], ['Infinity']];
    }

    #[DataProvider('invalidPurchaseCosts')]
    public function test_invalid_purchase_cost_is_a_row_error_and_later_rows_still_import(string $cost): void
    {
        $import = new ProductsImport($this->org->id);
        $import->collection(collect([
            collect(['sku' => 'BAD-COST', 'name' => 'Invalid cost', 'price' => 10, 'stock' => 1, 'purchase_price' => $cost]),
            collect(['sku' => 'GOOD-COST', 'name' => 'Valid cost', 'price' => 10, 'stock' => 1, 'purchase_price' => '2.50']),
        ]));
        $stats = $import->getStats();
        $this->assertCount(1, $stats['errors']);
        $this->assertSame(2, $stats['errors'][0]['row']);
        $this->assertSame(1, $stats['imported']);
        $this->assertDatabaseMissing('products', ['sku' => 'BAD-COST']);
        $this->assertSame('2.50', Product::where('sku', 'GOOD-COST')->sole()->purchase_price);
    }

    public static function optionalPurchaseCosts(): array
    {
        return [
            [[], null], [['purchase_price' => null], null], [['purchase_price' => ''], null],
            [['purchase_price' => '   '], null], [['purchase_price' => 0], '0.00'],
            [['purchase_price' => '0'], '0.00'], [['purchase_price' => '3.25'], '3.25'],
        ];
    }

    #[DataProvider('optionalPurchaseCosts')]
    public function test_purchase_cost_accepts_blank_zero_and_positive_values(array $cost, ?string $expected): void
    {
        $import = new ProductsImport($this->org->id);
        $import->collection(collect([collect(array_merge(['sku' => 'OPTIONAL-COST', 'name' => 'Optional cost', 'price' => 10, 'stock' => 1], $cost))]));
        $this->assertSame([], $import->getStats()['errors']);
        $this->assertSame($expected, Product::where('sku', 'OPTIONAL-COST')->sole()->purchase_price);
    }

    public function test_invalid_purchase_cost_does_not_mutate_an_existing_product(): void
    {
        $product = Product::create(['organization_id' => $this->org->id, 'sku' => 'EXISTING-COST', 'name' => 'Original', 'price' => 10, 'purchase_price' => '4.50', 'currency' => 'USD', 'stock' => 1]);
        $original = $product->fresh()->getAttributes();
        $import = new ProductsImport($this->org->id);
        $import->collection(collect([collect(['sku' => $product->sku, 'name' => 'Changed', 'price' => 20, 'stock' => 999, 'purchase_price' => -10])]));
        $this->assertCount(1, $import->getStats()['errors']);
        $this->assertSame(0, $import->getStats()['updated']);
        $this->assertSame($original, $product->fresh()->getAttributes());
    }
}
