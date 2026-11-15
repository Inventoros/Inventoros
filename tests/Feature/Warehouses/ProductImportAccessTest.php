<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Imports\ProductsImport;
use App\Jobs\ProcessProductImportJob;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The product import creates and updates products, places them in
 * locations and books stock (opening stock, or a recount for an existing
 * product). It must hold the importing user to the same product
 * permissions, stock permission, approval rules and warehouse assignments as
 * the screens that do the same things one at a time.
 */
final class ProductImportAccessTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();

        $role = $this->restricted->roles()->firstOrFail();
        $role->update(['permissions' => array_merge($this->stockPermissions, ['import_data', 'create_products'])]);
        $this->restricted->unsetRelation('roles');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function import(?User $actor, array $rows): ProductsImport
    {
        if ($actor !== null) {
            $this->actingAs($actor);
        }
        $import = new ProductsImport($this->organization->id, $actor);
        $import->collection(new Collection(array_map(fn (array $row) => collect($row), $rows)));

        return $import;
    }

    private function row(Product $product, int $stock, ?ProductLocation $location, array $extra = []): array
    {
        return array_merge([
            'sku' => $product->sku, 'name' => $product->name, 'price' => 10, 'stock' => $stock,
            'location' => $location?->name,
        ], $extra);
    }

    private function bin(Product $product, ProductLocation $location): int
    {
        return (int) ProductLocationStock::where('product_id', $product->id)->where('location_id', $location->id)->value('quantity');
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'name' => 'Importer '.uniqid(), 'slug' => 'importer-'.uniqid(), 'organization_id' => $this->organization->id,
            'is_system' => false, 'permissions' => $permissions,
        ]);
        $user = User::factory()->forOrganization($this->organization->id)->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function assertRowErrors(array $stats, int $count): void
    {
        $this->assertCount($count, $stats['errors'], json_encode($stats['errors']));
        $this->assertSame(0, $stats['imported'] + $stats['updated']);
    }

    // ---- Route -------------------------------------------------------

    public function test_route_requires_a_product_permission_as_well_as_import_data(): void
    {
        $importOnly = $this->userWith(['import_data']);
        $file = UploadedFile::fake()->createWithContent('products.csv', "sku,name,price,stock\nNEW-1,New,10,0\n");

        $this->actingAs($importOnly)->post(route('import-export.import-products'), ['file' => $file])->assertForbidden();
        $this->assertDatabaseMissing('products', ['sku' => 'NEW-1']);

        $editor = $this->userWith(['import_data', 'create_products']);
        $file = UploadedFile::fake()->createWithContent('products.csv', "sku,name,price,stock\nNEW-1,New,10,0\n");
        $this->actingAs($editor)->post(route('import-export.import-products'), ['file' => $file])->assertRedirect();
        $this->assertDatabaseHas('products', ['sku' => 'NEW-1']);
    }

    // ---- Product permissions per row ---------------------------------

    public function test_rows_need_create_products_to_create_and_edit_products_to_update(): void
    {
        $creatorOnly = $this->userWith(['import_data', 'create_products', 'manage_stock']);
        $stats = $this->import($creatorOnly, [$this->row($this->productA, 100, $this->locationA, ['name' => 'Renamed'])])->getStats();
        $this->assertRowErrors($stats, 1);
        $this->assertSame('Alpha Widget', $this->productA->fresh()->name);

        $editorOnly = $this->userWith(['import_data', 'edit_products']);
        $stats = $this->import($editorOnly, [['sku' => 'BRAND-NEW', 'name' => 'Brand New', 'price' => 10, 'stock' => 0]])->getStats();
        $this->assertRowErrors($stats, 1);
        $this->assertDatabaseMissing('products', ['sku' => 'BRAND-NEW']);
    }

    public function test_changing_stock_needs_manage_stock_but_unchanged_stock_does_not(): void
    {
        $editor = $this->userWith(['import_data', 'edit_products']);

        $stats = $this->import($editor, [$this->row($this->productA, 120, $this->locationA)])->getStats();
        $this->assertRowErrors($stats, 1);
        $this->assertSame(100, $this->productA->fresh()->stock);

        $stats = $this->import($editor, [$this->row($this->productA, 100, $this->locationA, ['name' => 'Renamed'])])->getStats();
        $this->assertSame([], $stats['errors']);
        $this->assertSame('Renamed', $this->productA->fresh()->name);
    }

    // ---- Approvals -----------------------------------------------------

    public function test_a_recount_that_would_need_approval_is_a_row_error(): void
    {
        $this->organization->update(['settings' => ['approvals' => ['stock_adjustments_enabled' => true, 'stock_adjustments_quantity_threshold' => 10]]]);

        $stats = $this->import($this->admin, [
            $this->row($this->productA, 150, $this->locationA),
            $this->row($this->productB, 55, $this->locationB),
        ])->getStats();

        $this->assertCount(1, $stats['errors']);
        $this->assertSame(2, $stats['errors'][0]['row']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertSame(55, $this->productB->fresh()->stock);
        $this->assertDatabaseCount('stock_adjustment_requests', 0);
    }

    // ---- Warehouses ----------------------------------------------------

    public function test_restricted_importer_cannot_set_stock_in_another_warehouse(): void
    {
        $stats = $this->import($this->restricted, [$this->row($this->productB, 5, $this->locationB)])->getStats();

        $this->assertRowErrors($stats, 1);
        $this->assertSame(50, $this->productB->fresh()->stock);
        $this->assertSame(50, $this->bin($this->productB, $this->locationB));
    }

    public function test_restricted_importer_cannot_repoint_products_across_warehouses(): void
    {
        $stats = $this->import($this->restricted, [
            $this->row($this->productA, 100, $this->locationB),
            $this->row($this->productB, 50, $this->locationA),
        ])->getStats();

        $this->assertRowErrors($stats, 2);
        $this->assertSame($this->locationA->id, $this->productA->fresh()->location_id);
        $this->assertSame($this->locationB->id, $this->productB->fresh()->location_id);
    }

    public function test_restricted_importer_cannot_create_products_or_locations_outside_their_warehouses(): void
    {
        $stats = $this->import($this->restricted, [
            ['sku' => 'NEW-B', 'name' => 'New B', 'price' => 10, 'stock' => 5, 'location' => $this->locationB->name],
            ['sku' => 'NEW-LOOSE', 'name' => 'Loose', 'price' => 10, 'stock' => 5],
            ['sku' => 'NEW-NOWHERE', 'name' => 'Nowhere', 'price' => 10, 'stock' => 0, 'location' => 'Brand New Shelf'],
        ])->getStats();

        $this->assertRowErrors($stats, 3);
        $this->assertDatabaseMissing('products', ['sku' => 'NEW-B']);
        $this->assertDatabaseMissing('products', ['sku' => 'NEW-LOOSE']);
        $this->assertDatabaseMissing('products', ['sku' => 'NEW-NOWHERE']);
        $this->assertDatabaseMissing('product_locations', ['name' => 'Brand New Shelf']);
    }

    public function test_restricted_recount_down_draws_only_from_assigned_bins(): void
    {
        // productA: 60 in A, 40 in B. Warehouse B outranks A.
        $this->warehouseB->update(['priority' => 100]);

        $stats = $this->import($this->restricted, [$this->row($this->productA, 30, $this->locationA)])->getStats();
        $this->assertRowErrors($stats, 1);
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertSame(40, $this->bin($this->productA, $this->locationB));

        $stats = $this->import($this->restricted, [$this->row($this->productA, 90, $this->locationA)])->getStats();
        $this->assertSame([], $stats['errors']);
        $this->assertSame(90, $this->productA->fresh()->stock);
        $this->assertSame(50, $this->bin($this->productA, $this->locationA));
        $this->assertSame(40, $this->bin($this->productA, $this->locationB));
    }

    public function test_restricted_importer_works_within_their_warehouse(): void
    {
        $stats = $this->import($this->restricted, [
            $this->row($this->productA, 110, $this->locationA),
            ['sku' => 'NEW-A', 'name' => 'New A', 'price' => 10, 'stock' => 7, 'location' => $this->locationA->name],
        ])->getStats();

        $this->assertSame([], $stats['errors']);
        $this->assertSame(110, $this->productA->fresh()->stock);
        $this->assertSame(70, $this->bin($this->productA, $this->locationA));
        $this->assertSame(7, $this->bin(Product::where('sku', 'NEW-A')->firstOrFail(), $this->locationA));
    }

    public function test_unrestricted_admin_import_is_unchanged(): void
    {
        $stats = $this->import($this->admin, [
            $this->row($this->productA, 70, $this->locationB),
            $this->row($this->productB, 60, $this->locationB),
            ['sku' => 'NEW-ANY', 'name' => 'Anywhere', 'price' => 10, 'stock' => 3, 'location' => 'Overflow Shelf'],
        ])->getStats();

        $this->assertSame([], $stats['errors']);
        $this->assertSame(1, $stats['imported']);
        $this->assertSame(2, $stats['updated']);
        $this->assertSame(70, $this->productA->fresh()->stock);
        $this->assertSame($this->locationB->id, $this->productA->fresh()->location_id);
        $this->assertSame(60, $this->productB->fresh()->stock);
        $this->assertDatabaseHas('product_locations', ['name' => 'Overflow Shelf']);
    }

    // ---- Queued import -------------------------------------------------

    public function test_queued_import_applies_the_importers_restrictions(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('imports/restricted.csv', "sku,name,price,stock,location\nBRAVO-1,Bravo Widget,10,5,Bravo Shelf\n");

        (new ProcessProductImportJob($this->organization->id, $this->restricted->id, 'local', 'imports/restricted.csv', 'Csv'))->handle();

        $this->assertSame(50, $this->productB->fresh()->stock);
        $this->assertSame(50, $this->bin($this->productB, $this->locationB));
    }
}
