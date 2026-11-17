<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * The GraphQL endpoint accepts GET for queries, but a mutation sent over GET
 * is refused before it runs: its arguments would otherwise end up in URLs,
 * server logs and caches.
 */
final class GraphQLGetRequestsTest extends TestCase
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

    public function test_a_query_can_be_sent_over_get(): void
    {
        $this->getJson('/graphql?'.http_build_query(['query' => '{ customers { id } }']))
            ->assertOk()
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.customers', []);
    }

    public function test_a_mutation_sent_over_get_is_refused_without_writing(): void
    {
        $response = $this->getJson('/graphql?'.http_build_query(['query' => 'mutation { createCustomer(name: "Over GET") { id } }']));

        $this->assertStringContainsString('GET supports only query operation', (string) $response->json('errors.0.message'));
        $this->assertDatabaseMissing('customers', ['name' => 'Over GET']);
    }

    public function test_the_same_mutation_still_runs_over_post(): void
    {
        $this->postJson('/graphql', ['query' => 'mutation { createCustomer(name: "Over POST") { id } }'])
            ->assertOk()
            ->assertJsonMissingPath('errors');

        $this->assertDatabaseHas('customers', ['name' => 'Over POST', 'organization_id' => $this->org->id]);
    }
}
