<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class GraphQLInputCapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_product_rejects_oversized_description(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->admin()->forOrganization($org->id)->create();
        Sanctum::actingAs($user, ['*']);

        $huge = str_repeat('a', 5001);
        $query = sprintf(
            'mutation { createProduct(sku: "CAP-1", name: "X", description: "%s") { id } }',
            $huge
        );
        $response = $this->postJson('/graphql', ['query' => $query]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertDatabaseMissing('products', ['sku' => 'CAP-1']);
    }

    public function test_create_product_accepts_normal_description(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->admin()->forOrganization($org->id)->create();
        Sanctum::actingAs($user, ['*']);

        $query = 'mutation { createProduct(sku: "CAP-2", name: "X", description: "fine") { id } }';
        $this->postJson('/graphql', ['query' => $query]);

        $this->assertDatabaseHas('products', ['sku' => 'CAP-2']);
    }
}
