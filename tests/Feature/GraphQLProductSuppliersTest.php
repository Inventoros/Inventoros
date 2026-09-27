<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GraphQL createProduct / updateProduct accept `suppliers`, validated and
 * org-scoped exactly like the web and REST surfaces, and persisted through
 * ProductService.
 */
final class GraphQLProductSuppliersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private Supplier $acme;

    private Supplier $globex;

    private Supplier $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $foreignOrg = Organization::factory()->create();
        $this->user = User::factory()->admin()->forOrganization($this->org->id)->create();

        $this->acme = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'is_active' => true]);
        $this->globex = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Globex', 'is_active' => true]);
        $this->foreign = Supplier::create(['organization_id' => $foreignOrg->id, 'name' => 'Foreign', 'is_active' => true]);

        Sanctum::actingAs($this->user, ['*']);
    }

    private function graphql(string $query): TestResponse
    {
        return $this->postJson('/graphql', ['query' => $query]);
    }

    public function test_create_product_links_suppliers(): void
    {
        $response = $this->graphql(sprintf(
            'mutation { createProduct(sku: "GQL-S1", name: "X", suppliers: [
                {supplier_id: %d, supplier_sku: "AC-1", cost_price: 4.5, lead_time_days: 3, minimum_order_quantity: 12, is_primary: true},
                {supplier_id: %d, cost_price: 5}
            ]) { id suppliers { id supplier_sku cost_price lead_time_days is_primary } } }',
            $this->acme->id,
            $this->globex->id
        ));

        $response->assertJsonMissingPath('errors');

        $suppliers = Product::where('sku', 'GQL-S1')->sole()->suppliers()->get()->keyBy('id');
        $this->assertCount(2, $suppliers);
        $this->assertSame('AC-1', $suppliers[$this->acme->id]->pivot->supplier_sku);
        $this->assertEquals(4.5, (float) $suppliers[$this->acme->id]->pivot->cost_price);
        $this->assertSame(3, $suppliers[$this->acme->id]->pivot->lead_time_days);
        $this->assertSame(12, $suppliers[$this->acme->id]->pivot->minimum_order_quantity);
        $this->assertTrue($suppliers[$this->acme->id]->pivot->is_primary);
        $this->assertFalse($suppliers[$this->globex->id]->pivot->is_primary);
    }

    public function test_create_product_rejects_a_supplier_from_another_organization(): void
    {
        $response = $this->graphql(sprintf(
            'mutation { createProduct(sku: "GQL-S2", name: "X", suppliers: [{supplier_id: %d, is_primary: true}]) { id } }',
            $this->foreign->id
        ));

        $this->assertArrayHasKey('suppliers.0.supplier_id', $response->json('errors.0.extensions.validation') ?? []);
        $this->assertDatabaseMissing('products', ['sku' => 'GQL-S2']);
        $this->assertDatabaseCount('product_supplier', 0);
    }

    public function test_create_product_rejects_two_primary_suppliers(): void
    {
        $response = $this->graphql(sprintf(
            'mutation { createProduct(sku: "GQL-S3", name: "X", suppliers: [
                {supplier_id: %d, is_primary: true}, {supplier_id: %d, is_primary: true}
            ]) { id } }',
            $this->acme->id,
            $this->globex->id
        ));

        $this->assertArrayHasKey('suppliers', $response->json('errors.0.extensions.validation') ?? []);
        $this->assertDatabaseMissing('products', ['sku' => 'GQL-S3']);
    }

    public function test_a_minimum_order_quantity_below_one_is_rejected(): void
    {
        $response = $this->graphql(sprintf(
            'mutation { createProduct(sku: "GQL-S4", name: "X", suppliers: [{supplier_id: %d, minimum_order_quantity: 0}]) { id } }',
            $this->acme->id
        ));

        $this->assertArrayHasKey('suppliers.0.minimum_order_quantity', $response->json('errors.0.extensions.validation') ?? []);
    }

    public function test_update_product_replaces_suppliers_when_sent(): void
    {
        $product = Product::factory()->create(['organization_id' => $this->org->id]);
        $product->suppliers()->attach($this->acme->id, ['cost_price' => 5, 'is_primary' => true]);

        $this->graphql(sprintf(
            'mutation { updateProduct(id: %d, suppliers: [{supplier_id: %d, cost_price: 6, is_primary: true}]) { id } }',
            $product->id,
            $this->globex->id
        ))->assertJsonMissingPath('errors');

        $this->assertSame([$this->globex->id], $product->suppliers()->pluck('suppliers.id')->all());
    }

    public function test_update_product_without_suppliers_leaves_links_untouched(): void
    {
        $product = Product::factory()->create(['organization_id' => $this->org->id]);
        $product->suppliers()->attach($this->acme->id, ['cost_price' => 5, 'is_primary' => true]);

        $this->graphql(sprintf('mutation { updateProduct(id: %d, name: "Renamed") { id } }', $product->id))
            ->assertJsonMissingPath('errors');

        $this->assertSame([$this->acme->id], $product->suppliers()->pluck('suppliers.id')->all());
    }

    public function test_update_product_rejects_a_cross_tenant_supplier_and_keeps_links(): void
    {
        $product = Product::factory()->create(['organization_id' => $this->org->id]);
        $product->suppliers()->attach($this->acme->id, ['cost_price' => 5, 'is_primary' => true]);

        $response = $this->graphql(sprintf(
            'mutation { updateProduct(id: %d, suppliers: [{supplier_id: %d, is_primary: true}]) { id } }',
            $product->id,
            $this->foreign->id
        ));

        $this->assertArrayHasKey('suppliers.0.supplier_id', $response->json('errors.0.extensions.validation') ?? []);
        $this->assertSame([$this->acme->id], $product->suppliers()->pluck('suppliers.id')->all());
    }
}
