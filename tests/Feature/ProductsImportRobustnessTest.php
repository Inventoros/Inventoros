<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\ProductsImport;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

        $import = new ProductsImport($this->org->id);
        $import->collection(new Collection([
            collect(['sku' => 'REIMP-1', 'name' => 'Restored', 'price' => 10, 'stock' => 20]),
        ]));

        $fresh = Product::withTrashed()->where('sku', 'REIMP-1')->first();
        $this->assertNull($fresh->deleted_at, 'The soft-deleted product should be restored.');
        $this->assertSame('Restored', $fresh->name);
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
}
