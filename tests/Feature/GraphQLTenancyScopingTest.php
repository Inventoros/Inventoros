<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class GraphQLTenancyScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_product_rejects_foreign_category(): void
    {
        $org = Organization::factory()->create();
        $foreignOrg = Organization::factory()->create();
        $user = User::factory()->admin()->forOrganization($org->id)->create();
        $foreignCategory = ProductCategory::factory()->create(['organization_id' => $foreignOrg->id]);

        Sanctum::actingAs($user, ['*']);

        $query = sprintf(
            'mutation { createProduct(sku: "GQL-1", name: "X", category_id: %d) { id } }',
            $foreignCategory->id
        );
        $response = $this->postJson('/graphql', ['query' => $query]);

        $response->assertJsonPath('errors.0.message', 'Category not found');
        $this->assertDatabaseMissing('products', ['sku' => 'GQL-1']);
    }

    public function test_create_product_accepts_own_category(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->admin()->forOrganization($org->id)->create();
        $category = ProductCategory::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user, ['*']);

        $query = sprintf(
            'mutation { createProduct(sku: "GQL-2", name: "X", category_id: %d) { id } }',
            $category->id
        );
        $this->postJson('/graphql', ['query' => $query]);

        $this->assertDatabaseHas('products', ['sku' => 'GQL-2', 'category_id' => $category->id]);
    }

    public function test_update_product_rejects_foreign_location(): void
    {
        $org = Organization::factory()->create();
        $foreignOrg = Organization::factory()->create();
        $user = User::factory()->admin()->forOrganization($org->id)->create();
        $product = Product::factory()->create(['organization_id' => $org->id]);
        $foreignLocation = ProductLocation::factory()->create(['organization_id' => $foreignOrg->id]);

        Sanctum::actingAs($user, ['*']);

        $query = sprintf(
            'mutation { updateProduct(id: %d, location_id: %d) { id } }',
            $product->id,
            $foreignLocation->id
        );
        $response = $this->postJson('/graphql', ['query' => $query]);

        $response->assertJsonPath('errors.0.message', 'Location not found');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'location_id' => null]);
    }
}
