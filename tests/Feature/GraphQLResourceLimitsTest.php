<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

final class GraphQLResourceLimitsTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    #[DataProvider('legacyLists')]
    public function test_negative_limit_cannot_remove_the_database_limit(string $field, string $table): void
    {
        $org = $this->makeOrganization();
        Sanctum::actingAs($this->makeAdmin($org));
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries, $table): void {
            if (preg_match('/\bfrom ["`]?'.preg_quote($table, '/').'["`]?(?:\s|$)/i', $event->sql)) {
                $queries[] = $event->sql;
            }
        });

        $this->postJson('/graphql', ['query' => "{ {$field}(limit: -1) { id } }"])
            ->assertJsonMissingPath('errors')
            ->assertJsonCount(0, 'data.'.$field);

        $this->assertNotEmpty($queries, 'The list resolver must execute against the requested table.');
        foreach ($queries as $sql) {
            $this->assertStringContainsString('limit 0', $sql, 'A negative limit must never generate an unbounded SELECT.');
        }
    }

    public static function legacyLists(): array
    {
        return [
            ['products', 'products'],
            ['orders', 'orders'],
            ['suppliers', 'suppliers'],
            ['locations', 'product_locations'],
            ['categories', 'product_categories'],
            ['purchaseOrders', 'purchase_orders'],
            ['stockAdjustments', 'stock_adjustments'],
        ];
    }

    public function test_product_limits_keep_defaults_upper_cap_and_zero_behavior(): void
    {
        $org = $this->makeOrganization();
        Sanctum::actingAs($this->makeAdmin($org));
        Product::factory()->count(101)->create(['organization_id' => $org->id]);

        $this->postJson('/graphql', ['query' => '{
            defaultList: products { id }
            capped: products(limit: 1000) { id }
            empty: products(limit: 0) { id }
            small: products(limit: 2) { id }
        }'])
            ->assertJsonMissingPath('errors')
            ->assertJsonCount(50, 'data.defaultList')
            ->assertJsonCount(100, 'data.capped')
            ->assertJsonCount(0, 'data.empty')
            ->assertJsonCount(2, 'data.small');
    }

    public function test_a_batch_cannot_execute_mutations_under_one_rate_limit_hit(): void
    {
        $org = $this->makeOrganization();
        Sanctum::actingAs($this->makeAdmin($org));
        $operation = ['query' => 'mutation { createCustomer(name: "Batched write") { id } }'];

        $response = $this->postJson('/graphql', [$operation, $operation]);

        $response->assertOk();
        $this->assertSame('Batch request received but batching is not supported', $response->json('0.errors.0.message'));
        $this->assertSame('Batch request received but batching is not supported', $response->json('1.errors.0.message'));
        $this->assertDatabaseMissing('customers', ['name' => 'Batched write']);

        $this->postJson('/graphql', $operation)
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.createCustomer.id', fn ($id) => is_int($id));
        $this->assertDatabaseHas('customers', ['organization_id' => $org->id, 'name' => 'Batched write']);
    }

    public function test_a_product_supplier_cycle_is_rejected_before_any_list_is_loaded(): void
    {
        $org = $this->makeOrganization();
        Sanctum::actingAs($this->makeAdmin($org));
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            if (preg_match('/\bfrom ["`]?(products|suppliers)["`]?(?:\s|$)/i', $event->sql)) {
                $queries[] = $event->sql;
            }
        });

        $response = $this->postJson('/graphql', [
            'query' => 'query ($count: Int!) { suppliers(limit: $count) { products { suppliers { products { id } } } } }',
            'variables' => ['count' => 1],
        ]);

        $this->assertStringContainsString('response node budget', $response->json('errors.0.message'));
        $this->assertSame([], $queries, 'Cost validation must refuse amplification before resolver queries run.');
    }

    public function test_ordinary_relationship_queries_work_and_never_return_more_than_one_hundred_children(): void
    {
        $org = $this->makeOrganization();
        Sanctum::actingAs($this->makeAdmin($org));
        $supplier = Supplier::create(['organization_id' => $org->id, 'name' => 'Acme', 'is_active' => true]);
        $products = Product::factory()->count(101)->create(['organization_id' => $org->id]);
        $supplier->products()->attach($products->modelKeys());

        $this->postJson('/graphql', ['query' => "{ supplier(id: {$supplier->id}) { id products { id name } } }"])
            ->assertJsonMissingPath('errors')
            ->assertJsonCount(100, 'data.supplier.products');

        $this->postJson('/graphql', ['query' => 'query ($count: Int!) { products(limit: $count) { id suppliers { id name } } }', 'variables' => ['count' => 2]])
            ->assertJsonMissingPath('errors')
            ->assertJsonCount(2, 'data.products')
            ->assertJsonCount(1, 'data.products.0.suppliers');
    }

    public function test_the_existing_document_complexity_limit_still_rejects_excessive_aliases(): void
    {
        $org = $this->makeOrganization();
        Sanctum::actingAs($this->makeAdmin($org));
        $fields = array_map(fn (int $index) => "p{$index}: product(id: 1) { id }", range(1, 101));

        $response = $this->postJson('/graphql', ['query' => '{ '.implode(' ', $fields).' }']);

        $this->assertStringContainsString('Max query complexity should be 200', $response->json('errors.0.message'));
    }

    public function test_truncating_variant_relationships_does_not_truncate_stock_totals(): void
    {
        $org = $this->makeOrganization();
        Sanctum::actingAs($this->makeAdmin($org));
        $product = $this->makeProduct($org, ['has_variants' => true, 'stock' => 0]);
        $variants = array_map(fn (int $index) => [
            'organization_id' => $org->id,
            'product_id' => $product->id,
            'sku' => 'VAR-'.$index,
            'option_values' => '{}',
            'stock' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], range(1, 101));
        ProductVariant::insert($variants);

        $this->postJson('/graphql', ['query' => "{ product(id: {$product->id}) { total_stock available_stock variants { id } } }"])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.product.total_stock', 101)
            ->assertJsonPath('data.product.available_stock', 101)
            ->assertJsonCount(100, 'data.product.variants');
    }

    public function test_pending_approval_candidates_are_bounded_before_hydration(): void
    {
        $org = $this->makeOrganization();
        Sanctum::actingAs($this->makeAdmin($org));
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            if (preg_match('/\bfrom ["`]?(purchase_orders|stock_transfers|stock_adjustment_requests|orders)["`]?(?:\s|$)/i', $event->sql)) {
                $queries[] = $event->sql;
            }
        });

        $this->postJson('/graphql', ['query' => '{ pendingApprovals { id } }'])
            ->assertJsonMissingPath('errors');

        $this->assertCount(4, $queries);
        foreach ($queries as $sql) {
            $this->assertStringContainsString('limit 100', $sql);
        }
    }
}
