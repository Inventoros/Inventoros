<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreateProductTool;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Creating a product with opening stock, or pointing a product's primary
 * location somewhere else, books stock into (or redirects future stock to) a
 * location. A warehouse-restricted user may only do that within their own
 * warehouses, on every surface.
 */
final class ProductOpeningStockLocationAccessTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();
        $role = $this->restricted->roles()->firstOrFail();
        $role->update(['permissions' => array_merge($this->stockPermissions, ['create_products'])]);
        $this->restricted->unsetRelation('roles');
    }

    private function webPayload(string $sku, ?ProductLocation $location, int $stock): array
    {
        return [
            'sku' => $sku, 'name' => "Product {$sku}", 'price' => 10, 'currency' => 'USD',
            'stock' => $stock, 'min_stock' => 0, 'location_id' => $location?->id,
        ];
    }

    private function bin(Product $product, ProductLocation $location): int
    {
        return (int) ProductLocationStock::where('product_id', $product->id)->where('location_id', $location->id)->value('quantity');
    }

    private function assertNoProduct(string $sku): void
    {
        $this->assertDatabaseMissing('products', ['sku' => $sku]);
        $this->assertDatabaseMissing('stock_adjustments', ['type' => 'opening_stock']);
    }

    public function test_web_cannot_create_a_product_with_opening_stock_in_an_unassigned_warehouse(): void
    {
        $this->actingAs($this->restricted)
            ->from(route('products.create'))
            ->post(route('products.store'), $this->webPayload('WEB-B', $this->locationB, 5))
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('location_id');

        $this->assertNoProduct('WEB-B');
        $this->assertSame(50, $this->bin($this->productB, $this->locationB));
    }

    public function test_web_cannot_place_a_new_product_in_an_unassigned_warehouse_even_without_stock(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('products.store'), $this->webPayload('WEB-B0', $this->locationB, 0))
            ->assertSessionHasErrors('location_id');

        $this->assertNoProduct('WEB-B0');
    }

    public function test_web_cannot_book_opening_stock_with_no_location(): void
    {
        // Unassigned stock belongs to no warehouse, so it is outside a
        // restricted user's warehouses.
        $this->actingAs($this->restricted)
            ->post(route('products.store'), $this->webPayload('WEB-NONE', null, 5))
            ->assertSessionHasErrors('location_id');

        $this->assertNoProduct('WEB-NONE');
    }

    public function test_web_creates_a_product_with_opening_stock_in_an_assigned_warehouse(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('products.store'), $this->webPayload('WEB-A', $this->locationA, 5))
            ->assertRedirect(route('products.index'));

        $product = Product::where('sku', 'WEB-A')->firstOrFail();
        $this->assertSame(5, $product->stock);
        $this->assertSame(5, $this->bin($product, $this->locationA));
        $this->assertDatabaseHas('stock_adjustments', ['product_id' => $product->id, 'type' => 'opening_stock', 'location_id' => $this->locationA->id]);
    }

    public function test_web_cannot_repoint_a_product_into_or_out_of_an_unassigned_warehouse(): void
    {
        $update = fn (Product $product, ProductLocation $location) => $this->actingAs($this->restricted)
            ->put(route('products.update', $product), [
                'sku' => $product->sku, 'name' => $product->name, 'price' => 10, 'currency' => 'USD',
                'min_stock' => 5, 'location_id' => $location->id,
            ]);

        $update($this->productA, $this->locationB)->assertSessionHasErrors('location_id');
        $update($this->productB, $this->locationA)->assertSessionHasErrors('location_id');

        $this->assertSame($this->locationA->id, $this->productA->fresh()->location_id);
        $this->assertSame($this->locationB->id, $this->productB->fresh()->location_id);
    }

    public function test_web_can_repoint_within_assigned_warehouses_and_save_an_unchanged_location(): void
    {
        $otherA = ProductLocation::create([
            'organization_id' => $this->organization->id, 'warehouse_id' => $this->warehouseA->id,
            'name' => 'Alpha Back Room', 'code' => 'A-2', 'is_active' => true,
        ]);
        $payload = fn (Product $product, ProductLocation $location, string $name) => [
            'sku' => $product->sku, 'name' => $name, 'price' => 10, 'currency' => 'USD',
            'min_stock' => 5, 'location_id' => $location->id,
        ];

        $this->actingAs($this->restricted)->put(route('products.update', $this->productA), $payload($this->productA, $otherA, 'Moved'))
            ->assertRedirect(route('products.index'));
        $this->assertSame($otherA->id, $this->productA->fresh()->location_id);

        // Editing other fields of a product located elsewhere is unchanged:
        // only a change of location is checked.
        $this->actingAs($this->restricted)->put(route('products.update', $this->productB), $payload($this->productB, $this->locationB, 'Renamed'))
            ->assertRedirect(route('products.index'));
        $this->assertSame('Renamed', $this->productB->fresh()->name);
    }

    public function test_api_cannot_create_or_repoint_into_an_unassigned_warehouse(): void
    {
        Sanctum::actingAs($this->restricted, ['*']);

        $this->postJson('/api/v1/products', ['sku' => 'API-B', 'name' => 'Api B', 'price' => 10, 'stock' => 5, 'location_id' => $this->locationB->id])
            ->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->putJson("/api/v1/products/{$this->productA->id}", ['location_id' => $this->locationB->id])
            ->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->postJson('/api/v1/products', ['sku' => 'API-NONE', 'name' => 'Api None', 'price' => 10, 'stock' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->assertNoProduct('API-NONE');

        $this->assertNoProduct('API-B');
        $this->assertSame($this->locationA->id, $this->productA->fresh()->location_id);

        $this->postJson('/api/v1/products', ['sku' => 'API-A', 'name' => 'Api A', 'price' => 10, 'stock' => 5, 'location_id' => $this->locationA->id])
            ->assertCreated();
        $this->assertSame(5, $this->bin(Product::where('sku', 'API-A')->firstOrFail(), $this->locationA));
    }

    public function test_api_variant_opening_stock_cannot_land_in_an_unassigned_warehouse(): void
    {
        Sanctum::actingAs($this->restricted, ['*']);

        $this->postJson("/api/v1/products/{$this->productB->id}/variants", [
            'sku' => 'BRAVO-RED', 'option_values' => ['Color' => 'Red'], 'stock' => 5,
        ])->assertForbidden();
        $this->postJson("/api/v1/products/{$this->productB->id}/variants/bulk", ['variants' => [
            ['sku' => 'BRAVO-BLUE', 'option_values' => ['Color' => 'Blue'], 'stock' => 5],
        ]])->assertForbidden();

        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('variant_location_stocks', 0);
    }

    public function test_graphql_cannot_create_a_product_in_an_unassigned_warehouse(): void
    {
        Sanctum::actingAs($this->restricted, ['*']);

        $response = $this->postJson('/graphql', ['query' => sprintf(
            'mutation { createProduct(sku: "GQL-B", name: "Gql B", price: 10, stock: 5, location_id: %d) { id } }',
            $this->locationB->id,
        )]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertNoProduct('GQL-B');

        $response = $this->postJson('/graphql', ['query' => sprintf(
            'mutation { updateProduct(id: %d, location_id: %d) { id } }',
            $this->productA->id,
            $this->locationB->id,
        )]);
        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame($this->locationA->id, $this->productA->fresh()->location_id);
    }

    public function test_mcp_cannot_create_a_product_in_an_unassigned_warehouse(): void
    {
        InventorosServer::actingAs($this->restricted)->tool(CreateProductTool::class, [
            'sku' => 'MCP-B', 'name' => 'Mcp B', 'price' => 10, 'stock' => 5, 'location_id' => $this->locationB->id,
        ])->assertHasErrors();

        $this->assertNoProduct('MCP-B');
    }

    public function test_unrestricted_users_are_unchanged(): void
    {
        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->webPayload('ADMIN-B', $this->locationB, 5))
            ->assertRedirect(route('products.index'));
        $this->assertSame(5, $this->bin(Product::where('sku', 'ADMIN-B')->firstOrFail(), $this->locationB));

        // No assignments and no organization restriction: every warehouse
        // (the unassigned clerk shares the restricted clerk's role).
        $this->actingAs($this->unassigned)
            ->post(route('products.store'), $this->webPayload('FLOAT-NONE', null, 5))
            ->assertRedirect(route('products.index'));
        $this->assertSame(5, Product::where('sku', 'FLOAT-NONE')->firstOrFail()->stock);
    }
}
