<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * One HTTP request counts once against the API rate limiter, so a single
 * GraphQL operation must not carry an unbounded number of aliased writes,
 * and an order must not carry an unbounded number of lines.
 */
final class GraphQLMutationLimitsTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->org = $this->makeOrganization();
        Sanctum::actingAs($this->makeAdmin($this->org));
    }

    private function aliasedCreates(int $count): string
    {
        $fields = array_map(fn (int $i) => "c{$i}: createCustomer(name: \"Alias {$i}\") { id }", range(1, $count));

        return implode(' ', $fields);
    }

    public function test_a_mutation_with_too_many_root_fields_is_rejected_before_any_write(): void
    {
        $response = $this->postJson('/graphql', ['query' => 'mutation { '.$this->aliasedCreates(11).' }']);

        $response->assertOk();
        $this->assertStringContainsString('at most 10 root fields', (string) $response->json('errors.0.message'));
        $this->assertNull($response->json('data'));
        $this->assertDatabaseMissing('customers', ['organization_id' => $this->org->id]);
    }

    public function test_root_fields_inside_fragments_are_counted(): void
    {
        $query = 'mutation { ... on Mutation { '.$this->aliasedCreates(6).' } ...More } '
            .'fragment More on Mutation { m1: createCustomer(name: "M1") { id } m2: createCustomer(name: "M2") { id } '
            .'m3: createCustomer(name: "M3") { id } m4: createCustomer(name: "M4") { id } m5: createCustomer(name: "M5") { id } }';

        $response = $this->postJson('/graphql', ['query' => $query]);

        $this->assertStringContainsString('at most 10 root fields', (string) $response->json('errors.0.message'));
        $this->assertDatabaseMissing('customers', ['organization_id' => $this->org->id]);
    }

    public function test_a_mutation_within_the_limit_still_runs_every_field(): void
    {
        $this->postJson('/graphql', ['query' => 'mutation { '.$this->aliasedCreates(10).' }'])
            ->assertOk()
            ->assertJsonMissingPath('errors');

        $this->assertDatabaseCount('customers', 10);
    }

    public function test_the_limit_is_configurable(): void
    {
        config(['graphql.security.mutation_max_root_fields' => 2]);

        $response = $this->postJson('/graphql', ['query' => 'mutation { '.$this->aliasedCreates(3).' }']);

        $this->assertStringContainsString('at most 2 root fields', (string) $response->json('errors.0.message'));
        $this->assertDatabaseMissing('customers', ['organization_id' => $this->org->id]);
    }

    public function test_query_operations_are_not_subject_to_the_mutation_limit(): void
    {
        $fields = implode(' ', array_map(fn (int $i) => "p{$i}: products(limit: 1) { id }", range(1, 12)));

        $this->postJson('/graphql', ['query' => "{ {$fields} }"])
            ->assertOk()
            ->assertJsonMissingPath('errors');
    }

    private function product(): Product
    {
        return $this->makeProduct($this->org, ['stock' => 10000]);
    }

    public function test_graphql_create_order_rejects_more_than_500_lines(): void
    {
        $product = $this->product();
        $items = implode(', ', array_fill(0, 501, "{ product_id: {$product->id}, quantity: 1 }"));

        $response = $this->postJson('/graphql', [
            'query' => "mutation { createOrder(customer_name: \"Bulk\", items: [{$items}]) { id } }",
        ]);

        $this->assertSame('validation', $response->json('errors.0.message'), (string) $response->getContent());
        $this->assertNotEmpty($response->json('errors.0.extensions.validation.items'));
        $this->assertDatabaseMissing('orders', ['customer_name' => 'Bulk']);
    }

    public function test_graphql_create_order_accepts_a_normal_order(): void
    {
        $product = $this->product();

        $this->postJson('/graphql', [
            'query' => "mutation { createOrder(customer_name: \"Normal\", items: [{ product_id: {$product->id}, quantity: 2 }]) { id } }",
        ])->assertJsonMissingPath('errors');

        $this->assertDatabaseHas('orders', ['customer_name' => 'Normal']);
    }

    public function test_rest_create_order_rejects_more_than_500_lines(): void
    {
        $product = $this->product();

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Bulk',
            'items' => array_fill(0, 501, ['product_id' => $product->id, 'quantity' => 1]),
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);

        $this->assertDatabaseMissing('orders', ['customer_name' => 'Bulk']);
    }

    public function test_rest_create_order_accepts_500_lines(): void
    {
        $product = $this->product();

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Large',
            'items' => array_fill(0, 500, ['product_id' => $product->id, 'quantity' => 1]),
        ])->assertCreated();

        $this->assertDatabaseHas('orders', ['customer_name' => 'Large']);
    }
}
