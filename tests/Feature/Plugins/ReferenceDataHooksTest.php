<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Imports\ProductsImport;
use App\Models\Auth\Organization;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\Supplier;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The supplier, category, location and warehouse created/updated/deleted
 * hooks fire from the model layer: exactly once per change, after it
 * commits, whichever surface made it (web, REST, GraphQL, imports).
 */
final class ReferenceDataHooksTest extends TestCase
{
    use RefreshDatabase;

    private const HOOKS = [
        'supplier_created', 'supplier_updated', 'supplier_before_delete', 'supplier_deleted',
        'category_created', 'category_updated', 'category_deleted',
        'location_created', 'location_updated', 'location_deleted',
        'warehouse_created', 'warehouse_updated', 'warehouse_deleted',
    ];

    private Organization $org;

    private User $admin;

    /** @var array<string, array<int, array<int, mixed>>> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Ref', 'email' => 'ref@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@ref.test', 'password' => bcrypt('password'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        foreach (self::HOOKS as $hook) {
            add_action($hook, function (...$args) use ($hook) {
                $this->fired[$hook][] = $args;
            });
        }
    }

    /**
     * @param  array<string, int>  $expected  hook => times; unlisted hooks must not fire
     */
    private function assertFiredExactly(array $expected): void
    {
        $actual = array_map('count', $this->fired);
        ksort($actual);
        ksort($expected);

        $this->assertSame($expected, $actual);
        $this->fired = [];
    }

    private function graphql(string $query): void
    {
        $response = $this->postJson('/graphql', ['query' => $query]);
        $response->assertOk();
        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
    }

    // ---------------------------------------------------------------
    // Suppliers
    // ---------------------------------------------------------------

    public function test_supplier_hooks_fire_once_on_the_web(): void
    {
        $this->actingAs($this->admin)->post(route('suppliers.store'), ['name' => 'Web Supply'])->assertSessionHasNoErrors();
        $this->assertFiredExactly(['supplier_created' => 1]);

        $supplier = Supplier::where('name', 'Web Supply')->sole();
        $this->actingAs($this->admin)->put(route('suppliers.update', $supplier), ['name' => 'Web Supply 2'])->assertSessionHasNoErrors();
        $this->assertFiredExactly(['supplier_updated' => 1]);

        $this->actingAs($this->admin)->delete(route('suppliers.destroy', $supplier));
        $this->assertFiredExactly(['supplier_before_delete' => 1, 'supplier_deleted' => 1]);
    }

    public function test_supplier_created_passes_the_supplier_and_the_acting_user(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/suppliers', ['name' => 'Api Supply'])->assertCreated();

        [$supplier, $user] = $this->fired['supplier_created'][0];
        $this->assertSame('Api Supply', $supplier->name);
        $this->assertTrue($user->is($this->admin));
    }

    public function test_supplier_hooks_fire_once_over_rest(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/suppliers', ['name' => 'Api Supply'])->assertCreated()->json('data.id');
        $this->assertFiredExactly(['supplier_created' => 1]);

        $this->putJson("/api/v1/suppliers/{$id}", ['name' => 'Api Supply 2'])->assertOk();
        $this->assertFiredExactly(['supplier_updated' => 1]);

        $this->deleteJson("/api/v1/suppliers/{$id}")->assertOk();
        $this->assertFiredExactly(['supplier_before_delete' => 1, 'supplier_deleted' => 1]);
    }

    public function test_supplier_hooks_fire_once_over_graphql(): void
    {
        Sanctum::actingAs($this->admin);

        $this->graphql('mutation { createSupplier(name: "Gql Supply") { id } }');
        $this->assertFiredExactly(['supplier_created' => 1]);

        $supplier = Supplier::where('name', 'Gql Supply')->sole();
        $this->graphql("mutation { updateSupplier(id: {$supplier->id}, name: \"Gql Supply 2\") { id } }");
        $this->assertFiredExactly(['supplier_updated' => 1]);
    }

    public function test_nothing_fires_when_the_supplier_change_rolls_back(): void
    {
        try {
            DB::transaction(function () {
                Supplier::create(['organization_id' => $this->org->id, 'name' => 'Ghost']);
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
        }

        $this->assertFiredExactly([]);
    }

    // ---------------------------------------------------------------
    // Categories
    // ---------------------------------------------------------------

    public function test_category_hooks_fire_once_on_the_web_and_over_rest(): void
    {
        $this->actingAs($this->admin)->post(route('categories.store'), ['name' => 'Web Cat'])->assertSessionHasNoErrors();
        $this->assertFiredExactly(['category_created' => 1]);

        $category = ProductCategory::where('name', 'Web Cat')->sole();
        $this->actingAs($this->admin)->put(route('categories.update', $category), ['name' => 'Web Cat 2'])->assertSessionHasNoErrors();
        $this->assertFiredExactly(['category_updated' => 1]);

        $this->actingAs($this->admin)->delete(route('categories.destroy', $category));
        $this->assertFiredExactly(['category_deleted' => 1]);

        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/categories', ['name' => 'Api Cat'])->assertCreated()->json('data.id');
        $this->assertFiredExactly(['category_created' => 1]);

        $this->putJson("/api/v1/categories/{$id}", ['name' => 'Api Cat 2'])->assertOk();
        $this->assertFiredExactly(['category_updated' => 1]);

        $this->deleteJson("/api/v1/categories/{$id}")->assertOk();
        $this->assertFiredExactly(['category_deleted' => 1]);
    }

    // ---------------------------------------------------------------
    // Locations
    // ---------------------------------------------------------------

    public function test_location_hooks_fire_once_on_the_web_and_over_rest(): void
    {
        $this->actingAs($this->admin)->post(route('locations.store'), ['name' => 'Web Bin', 'code' => 'WEB-1'])->assertSessionHasNoErrors();
        $this->assertFiredExactly(['location_created' => 1]);

        $location = ProductLocation::where('code', 'WEB-1')->sole();
        $this->actingAs($this->admin)->put(route('locations.update', $location), ['name' => 'Web Bin 2', 'code' => 'WEB-1'])->assertSessionHasNoErrors();
        $this->assertFiredExactly(['location_updated' => 1]);

        $this->actingAs($this->admin)->delete(route('locations.destroy', $location));
        $this->assertFiredExactly(['location_deleted' => 1]);

        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/locations', ['name' => 'Api Bin', 'code' => 'API-1'])->assertCreated()->json('data.id');
        $this->assertFiredExactly(['location_created' => 1]);

        $this->putJson("/api/v1/locations/{$id}", ['name' => 'Api Bin 2'])->assertOk();
        $this->assertFiredExactly(['location_updated' => 1]);

        $this->deleteJson("/api/v1/locations/{$id}")->assertOk();
        $this->assertFiredExactly(['location_deleted' => 1]);
    }

    public function test_a_product_import_announces_the_categories_and_locations_it_creates(): void
    {
        $this->actingAs($this->admin);

        (new ProductsImport($this->org->id, $this->admin))->collection(collect([
            collect(['sku' => 'IMP-1', 'name' => 'One', 'price' => 5, 'stock' => 1, 'category' => 'Imported', 'location' => 'Shelf 9']),
            collect(['sku' => 'IMP-2', 'name' => 'Two', 'price' => 5, 'stock' => 1, 'category' => 'Imported', 'location' => 'Shelf 9']),
        ]));

        $this->assertSame(1, count($this->fired['category_created'] ?? []));
        $this->assertSame(1, count($this->fired['location_created'] ?? []));
    }

    // ---------------------------------------------------------------
    // Warehouses
    // ---------------------------------------------------------------

    public function test_warehouse_hooks_fire_once_on_the_web_and_over_rest(): void
    {
        $this->actingAs($this->admin)->post(route('warehouses.store'), ['name' => 'Web House', 'code' => 'WH-WEB'])->assertSessionHasNoErrors();
        $this->assertSame(1, count($this->fired['warehouse_created'] ?? []));
        $this->fired = [];

        $warehouse = Warehouse::where('name', 'Web House')->sole();
        $this->actingAs($this->admin)->put(route('warehouses.update', $warehouse), ['name' => 'Web House 2', 'code' => 'WH-WEB'])->assertSessionHasNoErrors();
        $this->assertFiredExactly(['warehouse_updated' => 1]);

        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/warehouses', ['name' => 'Api House', 'code' => 'WH-API'])->assertCreated()->json('data.id');
        $this->assertSame(1, count($this->fired['warehouse_created'] ?? []));
        $this->fired = [];

        $this->putJson("/api/v1/warehouses/{$id}", ['name' => 'Api House 2'])->assertOk();
        $this->assertFiredExactly(['warehouse_updated' => 1]);

        $this->deleteJson("/api/v1/warehouses/{$id}")->assertOk();
        $this->assertSame(1, count($this->fired['warehouse_deleted'] ?? []));
    }
}
