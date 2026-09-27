<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\SupplierPriceHistory;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Products can be linked to suppliers (supplier SKU, cost, lead time, one
 * primary) from the web form and the REST API, through ProductService.
 */
class ProductSupplierLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Supplier $acme;

    private Supplier $globex;

    private Supplier $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $other = Organization::create(['name' => 'Other', 'email' => 'x@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->acme = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'code' => 'ACME', 'is_active' => true]);
        $this->globex = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Globex', 'code' => 'GLOBEX', 'is_active' => true]);
        $this->foreign = Supplier::create(['organization_id' => $other->id, 'name' => 'Foreign', 'code' => 'FOREIGN', 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Widget',
            'sku' => 'WID-1',
            'price' => 20,
            'currency' => 'USD',
            'stock' => 5,
            'min_stock' => 1,
        ], $overrides);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'organization_id' => $this->org->id, 'name' => 'Existing', 'sku' => 'EX-1',
            'price' => 10, 'currency' => 'USD', 'stock' => 3, 'min_stock' => 1,
        ]);
    }

    public function test_web_store_links_suppliers_with_pivot_data_and_one_primary(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), $this->productPayload([
            'suppliers' => [
                ['supplier_id' => $this->acme->id, 'supplier_sku' => 'AC-9', 'cost_price' => 7.5, 'lead_time_days' => 4, 'is_primary' => true],
                ['supplier_id' => $this->globex->id, 'supplier_sku' => null, 'cost_price' => 8, 'lead_time_days' => null, 'is_primary' => false],
            ],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $product = Product::where('sku', 'WID-1')->firstOrFail();
        $suppliers = $product->suppliers()->get()->keyBy('id');

        $this->assertCount(2, $suppliers);
        $this->assertSame('AC-9', $suppliers[$this->acme->id]->pivot->supplier_sku);
        $this->assertEquals(7.5, $suppliers[$this->acme->id]->pivot->cost_price);
        $this->assertEquals(4, $suppliers[$this->acme->id]->pivot->lead_time_days);
        $this->assertTrue((bool) $suppliers[$this->acme->id]->pivot->is_primary);
        $this->assertFalse((bool) $suppliers[$this->globex->id]->pivot->is_primary);
        $this->assertSame($this->acme->id, $product->primarySupplier()?->id);
    }

    public function test_web_store_rejects_a_supplier_from_another_organization(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), $this->productPayload([
            'suppliers' => [
                ['supplier_id' => $this->foreign->id, 'cost_price' => 1, 'is_primary' => true],
            ],
        ]))->assertSessionHasErrors('suppliers.0.supplier_id');

        $this->assertDatabaseMissing('products', ['sku' => 'WID-1']);
        $this->assertDatabaseCount('product_supplier', 0);
    }

    public function test_more_than_one_primary_supplier_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), $this->productPayload([
            'suppliers' => [
                ['supplier_id' => $this->acme->id, 'is_primary' => true],
                ['supplier_id' => $this->globex->id, 'is_primary' => true],
            ],
        ]))->assertSessionHasErrors('suppliers');

        $this->assertDatabaseCount('product_supplier', 0);
    }

    public function test_the_same_supplier_cannot_be_linked_twice(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), $this->productPayload([
            'suppliers' => [
                ['supplier_id' => $this->acme->id, 'is_primary' => true],
                ['supplier_id' => $this->acme->id, 'is_primary' => false],
            ],
        ]))->assertSessionHasErrors('suppliers.0.supplier_id');
    }

    public function test_when_no_supplier_is_flagged_primary_the_first_becomes_primary(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), $this->productPayload([
            'suppliers' => [
                ['supplier_id' => $this->globex->id, 'cost_price' => 3],
                ['supplier_id' => $this->acme->id, 'cost_price' => 4],
            ],
        ]))->assertSessionHasNoErrors();

        $product = Product::where('sku', 'WID-1')->firstOrFail();
        $this->assertSame($this->globex->id, $product->primarySupplier()?->id);
    }

    public function test_web_update_syncs_suppliers_and_switches_the_primary(): void
    {
        $product = $this->makeProduct();
        $product->suppliers()->attach($this->acme->id, ['cost_price' => 5, 'is_primary' => true]);

        $this->actingAs($this->admin)->put(route('products.update', $product), [
            'name' => 'Existing', 'sku' => 'EX-1', 'price' => 10, 'stock' => 3, 'min_stock' => 1,
            'suppliers' => [
                ['supplier_id' => $this->globex->id, 'cost_price' => 6, 'is_primary' => true],
            ],
        ])->assertSessionHasNoErrors();

        $linked = $product->suppliers()->get();
        $this->assertCount(1, $linked);
        $this->assertSame($this->globex->id, $linked->first()->id);
        $this->assertTrue((bool) $linked->first()->pivot->is_primary);
    }

    public function test_web_update_saves_and_validates_the_minimum_order_quantity(): void
    {
        $product = $this->makeProduct();
        $payload = fn ($moq) => [
            'name' => 'Existing', 'sku' => 'EX-1', 'price' => 10, 'stock' => 3, 'min_stock' => 1,
            'suppliers' => [['supplier_id' => $this->acme->id, 'minimum_order_quantity' => $moq, 'is_primary' => true]],
        ];

        $this->actingAs($this->admin)->put(route('products.update', $product), $payload(0))
            ->assertSessionHasErrors('suppliers.0.minimum_order_quantity');
        $this->actingAs($this->admin)->put(route('products.update', $product), $payload(2.5))
            ->assertSessionHasErrors('suppliers.0.minimum_order_quantity');

        $this->actingAs($this->admin)->put(route('products.update', $product), $payload(12))
            ->assertSessionHasNoErrors();
        $this->assertSame(12, $product->suppliers()->sole()->pivot->minimum_order_quantity);

        // Blank clears it.
        $this->actingAs($this->admin)->put(route('products.update', $product), $payload(null))
            ->assertSessionHasNoErrors();
        $this->assertNull($product->suppliers()->sole()->pivot->minimum_order_quantity);
    }

    public function test_web_update_with_an_empty_supplier_list_unlinks_all(): void
    {
        $product = $this->makeProduct();
        $product->suppliers()->attach($this->acme->id, ['cost_price' => 5, 'is_primary' => true]);

        $this->actingAs($this->admin)->put(route('products.update', $product), [
            'name' => 'Existing', 'sku' => 'EX-1', 'price' => 10, 'stock' => 3, 'min_stock' => 1,
            'suppliers' => [],
        ])->assertSessionHasNoErrors();

        $this->assertCount(0, $product->suppliers()->get());
    }

    public function test_web_update_rejects_a_cross_tenant_supplier_and_keeps_existing_links(): void
    {
        $product = $this->makeProduct();
        $product->suppliers()->attach($this->acme->id, ['cost_price' => 5, 'is_primary' => true]);

        $this->actingAs($this->admin)->put(route('products.update', $product), [
            'name' => 'Existing', 'sku' => 'EX-1', 'price' => 10, 'stock' => 3, 'min_stock' => 1,
            'suppliers' => [
                ['supplier_id' => $this->foreign->id, 'cost_price' => 1, 'is_primary' => true],
            ],
        ])->assertSessionHasErrors('suppliers.0.supplier_id');

        $this->assertSame([$this->acme->id], $product->suppliers()->pluck('suppliers.id')->all());
    }

    public function test_api_store_accepts_suppliers_and_the_resource_returns_them(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/products', $this->productPayload([
            'suppliers' => [
                ['supplier_id' => $this->acme->id, 'supplier_sku' => 'AC-1', 'cost_price' => 9.25, 'lead_time_days' => 7, 'is_primary' => true],
            ],
        ]))->assertCreated();

        $response->assertJsonPath('data.suppliers.0.id', $this->acme->id)
            ->assertJsonPath('data.suppliers.0.supplier_sku', 'AC-1')
            ->assertJsonPath('data.suppliers.0.lead_time_days', 7)
            ->assertJsonPath('data.suppliers.0.is_primary', true);
        $this->assertEquals(9.25, $response->json('data.suppliers.0.cost_price'));
    }

    public function test_api_store_rejects_a_cross_tenant_supplier(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/products', $this->productPayload([
            'suppliers' => [['supplier_id' => $this->foreign->id, 'is_primary' => true]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('suppliers.0.supplier_id');

        $this->assertDatabaseCount('product_supplier', 0);
    }

    public function test_api_update_without_suppliers_key_leaves_links_untouched(): void
    {
        Sanctum::actingAs($this->admin);
        $product = $this->makeProduct();
        $product->suppliers()->attach($this->acme->id, ['cost_price' => 5, 'is_primary' => true]);

        $this->putJson("/api/v1/products/{$product->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.suppliers.0.id', $this->acme->id);

        $this->assertCount(1, $product->suppliers()->get());
    }

    public function test_api_update_replaces_suppliers_when_sent(): void
    {
        Sanctum::actingAs($this->admin);
        $product = $this->makeProduct();
        $product->suppliers()->attach($this->acme->id, ['cost_price' => 5, 'is_primary' => true]);

        $this->putJson("/api/v1/products/{$product->id}", [
            'suppliers' => [['supplier_id' => $this->globex->id, 'cost_price' => 4, 'is_primary' => true]],
        ])->assertOk();

        $this->assertSame([$this->globex->id], $product->suppliers()->pluck('suppliers.id')->all());
    }

    public function test_cost_changes_are_recorded_in_price_history(): void
    {
        $product = $this->makeProduct();
        $update = fn (array $suppliers) => $this->actingAs($this->admin)->put(route('products.update', $product), [
            'name' => 'Existing', 'sku' => 'EX-1', 'price' => 10, 'stock' => 3, 'min_stock' => 1,
            'suppliers' => $suppliers,
        ])->assertSessionHasNoErrors();

        // First link at a cost: one row.
        $update([['supplier_id' => $this->acme->id, 'cost_price' => 5, 'is_primary' => true]]);
        // Same cost again: no new row.
        $update([['supplier_id' => $this->acme->id, 'cost_price' => 5, 'is_primary' => true]]);
        // Cost changes: second row.
        $update([['supplier_id' => $this->acme->id, 'cost_price' => 6.5, 'is_primary' => true]]);

        $history = SupplierPriceHistory::where('product_id', $product->id)->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertEquals([5.0, 6.5], $history->pluck('cost_price')->map(fn ($c) => (float) $c)->all());
        $this->assertSame($this->admin->id, $history->last()->user_id);
        $this->assertSame($this->org->id, $history->last()->organization_id);
        $this->assertSame($this->acme->id, $history->last()->supplier_id);
        $this->assertSame(SupplierPriceHistory::SOURCE_SUPPLIER_LINK, $history->last()->source);
        $this->assertNotNull($history->last()->recorded_at);
    }

    public function test_product_show_lists_suppliers_and_price_history(): void
    {
        $product = $this->makeProduct();
        $this->actingAs($this->admin)->put(route('products.update', $product), [
            'name' => 'Existing', 'sku' => 'EX-1', 'price' => 10, 'stock' => 3, 'min_stock' => 1,
            'suppliers' => [['supplier_id' => $this->acme->id, 'cost_price' => 5, 'is_primary' => true]],
        ]);

        $this->actingAs($this->admin)->get(route('products.show', $product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Show')
                ->where('product.suppliers.0.id', $this->acme->id)
                ->where('product.suppliers.0.pivot.is_primary', true)
                ->has('priceHistory', 1)
                ->where('priceHistory.0.supplier_name', 'Acme')
            );
    }

    public function test_product_create_and_edit_pages_offer_only_own_organization_suppliers(): void
    {
        $product = $this->makeProduct();
        $product->suppliers()->attach($this->acme->id, ['cost_price' => 5, 'is_primary' => true]);

        $this->actingAs($this->admin)->get(route('products.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('suppliers', 2));

        $this->actingAs($this->admin)->get(route('products.edit', $product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('suppliers', 2)
                ->where('product.suppliers.0.id', $this->acme->id)
            );
    }

    public function test_supplier_show_lists_linked_products_with_cost(): void
    {
        $product = $this->makeProduct();
        $product->suppliers()->attach($this->acme->id, ['cost_price' => 5, 'is_primary' => true]);

        $this->actingAs($this->admin)->get(route('suppliers.show', $this->acme))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('supplier.products.0.id', $product->id)
                ->where('supplier.products.0.pivot.cost_price', '5.00')
            );
    }
}
