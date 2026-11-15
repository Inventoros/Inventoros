<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GraphQL list queries must only sort by the same allowlisted columns as the
 * REST endpoints. Ordering by any other column (a hidden financial field, a
 * JSON path) leaks the relative order of values the caller cannot read.
 */
final class GraphQLSortAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->forOrganization($this->org->id)->create(), ['*']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function listQueries(): array
    {
        return [
            'products' => ['products', 'name'],
            'orders' => ['orders', 'order_number'],
            'suppliers' => ['suppliers', 'name'],
            'stockAdjustments' => ['stockAdjustments', 'type'],
            'purchaseOrders' => ['purchaseOrders', 'po_number'],
        ];
    }

    #[DataProvider('listQueries')]
    public function test_a_column_outside_the_allowlist_is_a_validation_error(string $field): void
    {
        $response = $this->postJson('/graphql', [
            'query' => "{ {$field}(sort_by: \"organization_id\") { id } }",
        ]);

        $response->assertOk();
        $this->assertSame('validation', $response->json('errors.0.message'), (string) $response->getContent());
        $this->assertNotEmpty($response->json('errors.0.extensions.validation.sort_by'));
        $this->assertNull($response->json("data.{$field}"));
    }

    #[DataProvider('listQueries')]
    public function test_an_unknown_sort_direction_is_a_validation_error(string $field, string $column): void
    {
        $response = $this->postJson('/graphql', [
            'query' => "{ {$field}(sort_by: \"{$column}\", sort_dir: \"sideways\") { id } }",
        ]);

        $response->assertOk();
        $this->assertSame('validation', $response->json('errors.0.message'), (string) $response->getContent());
        $this->assertNotEmpty($response->json('errors.0.extensions.validation.sort_dir'));
    }

    #[DataProvider('listQueries')]
    public function test_an_allowlisted_column_and_direction_still_sort(string $field, string $column): void
    {
        $response = $this->postJson('/graphql', [
            'query' => "{ {$field}(sort_by: \"{$column}\", sort_dir: \"asc\") { id } }",
        ]);

        $response->assertOk();
        $this->assertNull($response->json('errors'), (string) $response->getContent());
        $this->assertIsArray($response->json("data.{$field}"));
    }

    #[DataProvider('listQueries')]
    public function test_direction_is_case_insensitive_and_id_is_sortable(string $field): void
    {
        foreach (['ASC', 'Desc', 'desc'] as $direction) {
            $response = $this->postJson('/graphql', [
                'query' => "{ {$field}(sort_by: \"id\", sort_dir: \"{$direction}\") { id } }",
            ]);

            $this->assertNull($response->json('errors'), (string) $response->getContent());
            $this->assertIsArray($response->json("data.{$field}"));
        }
    }

    public function test_graphql_sorts_by_id_in_an_upper_case_direction(): void
    {
        $ids = collect(['A', 'B', 'C'])->map(fn (string $name) => Product::create([
            'organization_id' => $this->org->id, 'sku' => 'ID-'.$name, 'name' => $name,
            'price' => 1, 'currency' => 'USD', 'stock' => 1, 'is_active' => true,
        ])->id);

        $this->assertSame(
            $ids->sortDesc()->values()->all(),
            array_map('intval', $this->postJson('/graphql', ['query' => '{ products(sort_by: "id", sort_dir: "DESC") { id } }'])->json('data.products.*.id'))
        );
    }

    public function test_rest_lists_accept_id_and_an_upper_case_direction(): void
    {
        $ids = collect(['A', 'B', 'C'])->map(fn (string $name) => Product::create([
            'organization_id' => $this->org->id, 'sku' => 'R-'.$name, 'name' => $name,
            'price' => 1, 'currency' => 'USD', 'stock' => 1, 'is_active' => true,
        ])->id);

        $this->assertSame(
            $ids->sort()->values()->all(),
            $this->getJson('/api/v1/products?sort_by=id&sort_dir=ASC')->assertOk()->json('data.*.id')
        );
    }

    public function test_graphql_argument_descriptions_list_the_allowed_columns(): void
    {
        $args = $this->postJson('/graphql', ['query' => '{ __type(name: "Query") { fields { name args { name description } } } }'])
            ->json('data.__type.fields');

        $products = collect($args)->firstWhere('name', 'products');
        $sortBy = collect($products['args'])->firstWhere('name', 'sort_by');

        foreach (['created_at', 'name', 'sku', 'id'] as $column) {
            $this->assertStringContainsString($column, $sortBy['description']);
        }
    }

    public function test_products_are_ordered_by_the_requested_allowlisted_column(): void
    {
        foreach (['Charlie', 'Alpha', 'Bravo'] as $name) {
            Product::create([
                'organization_id' => $this->org->id, 'sku' => 'S-'.$name, 'name' => $name,
                'price' => 1, 'currency' => 'USD', 'stock' => 1, 'is_active' => true,
            ]);
        }

        $names = $this->postJson('/graphql', ['query' => '{ products(sort_by: "name", sort_dir: "asc") { name } }'])
            ->json('data.products.*.name');

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $names);
    }
}
