<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * GraphQL input ids must be validated against the caller's organization. An
 * unscoped exists rule answered differently for another tenant's id than for
 * an id that does not exist at all, revealing which foreign ids exist.
 */
final class GraphQLScopedExistsTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private const MISSING_ID = 999999;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Mine');
        $this->other = $this->makeOrganization('Theirs');
        Sanctum::actingAs($this->makeAdmin($this->org));
    }

    private function graphql(string $query): TestResponse
    {
        return $this->postJson('/graphql', ['query' => $query])->assertOk();
    }

    /**
     * The validation errors for one field, with no other differences allowed.
     *
     * @return array<int, string>|null
     */
    private function validationErrors(TestResponse $response, string $key): ?array
    {
        return $response->json('errors.0.extensions.validation')[$key] ?? null;
    }

    private function variant(Organization $org, Product $product): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id, 'organization_id' => $org->id, 'sku' => 'V-'.uniqid(),
            'option_values' => ['Size' => 'L'], 'stock' => 100, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
        ]);
    }

    public function test_stock_adjustment_answers_a_foreign_product_exactly_like_a_missing_one(): void
    {
        $foreign = $this->makeProduct($this->other);
        $mutation = 'mutation { createStockAdjustment(product_id: %d, quantity: 1, type: "manual") { id } }';

        $foreignErrors = $this->validationErrors($this->graphql(sprintf($mutation, $foreign->id)), 'product_id');
        $missingErrors = $this->validationErrors($this->graphql(sprintf($mutation, self::MISSING_ID)), 'product_id');

        $this->assertNotNull($missingErrors);
        $this->assertSame($missingErrors, $foreignErrors);
        $this->assertDatabaseMissing('stock_adjustments', ['product_id' => $foreign->id]);
    }

    public function test_stock_adjustment_still_accepts_an_own_product(): void
    {
        $product = $this->makeProduct($this->org, ['stock' => 5]);

        $response = $this->graphql("mutation { createStockAdjustment(product_id: {$product->id}, quantity: 2, type: \"manual\") { id } }");

        $this->assertNull($response->json('errors'), (string) $response->getContent());
        $this->assertDatabaseHas('stock_adjustments', ['product_id' => $product->id]);
    }

    public function test_order_items_answer_a_foreign_product_exactly_like_a_missing_one(): void
    {
        $foreign = $this->makeProduct($this->other);
        $mutation = 'mutation { createOrder(customer_name: "C", items: [{ product_id: %d, quantity: 1 }]) { id } }';

        $foreignErrors = $this->validationErrors($this->graphql(sprintf($mutation, $foreign->id)), 'items.0.product_id');
        $missingErrors = $this->validationErrors($this->graphql(sprintf($mutation, self::MISSING_ID)), 'items.0.product_id');

        $this->assertNotNull($missingErrors);
        $this->assertSame($missingErrors, $foreignErrors);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_items_answer_a_foreign_variant_exactly_like_a_missing_one(): void
    {
        $product = $this->makeProduct($this->org, ['has_variants' => true]);
        $foreignVariant = $this->variant($this->other, $this->makeProduct($this->other, ['has_variants' => true]));
        $mutation = 'mutation { createOrder(customer_name: "C", items: [{ product_id: %d, product_variant_id: %d, quantity: 1 }]) { id } }';

        $foreignErrors = $this->validationErrors($this->graphql(sprintf($mutation, $product->id, $foreignVariant->id)), 'items.0.product_variant_id');
        $missingErrors = $this->validationErrors($this->graphql(sprintf($mutation, $product->id, self::MISSING_ID)), 'items.0.product_variant_id');

        $this->assertNotNull($missingErrors);
        $this->assertSame($missingErrors, $foreignErrors);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_items_still_accept_own_products_and_variants(): void
    {
        $product = $this->makeProduct($this->org, ['has_variants' => true]);
        $variant = $this->variant($this->org, $product);

        $response = $this->graphql("mutation { createOrder(customer_name: \"Mine\", items: [{ product_id: {$product->id}, product_variant_id: {$variant->id}, quantity: 1, unit_price: 5 }]) { id } }");

        $this->assertNull($response->json('errors'), (string) $response->getContent());
        $this->assertDatabaseHas('orders', ['customer_name' => 'Mine', 'organization_id' => $this->org->id]);
    }

    public function test_product_mutations_answer_a_foreign_category_and_location_like_missing_ones(): void
    {
        $foreignCategory = ProductCategory::create([
            'organization_id' => $this->other->id, 'name' => 'Theirs', 'slug' => 'theirs', 'is_active' => true,
        ]);
        $foreignLocation = $this->makeLocation($this->other);
        $own = $this->makeProduct($this->org);

        foreach ([
            'category_id' => [$foreignCategory->id, 'createProduct(sku: "X-%s", name: "X", category_id: %d)', 'updateProduct(id: '.$own->id.', name: "X%s", category_id: %d)'],
            'location_id' => [$foreignLocation->id, 'createProduct(sku: "Y-%s", name: "Y", location_id: %d)', 'updateProduct(id: '.$own->id.', name: "Y%s", location_id: %d)'],
        ] as $field => [$foreignId, $create, $update]) {
            foreach ([$create, $update] as $template) {
                $foreignErrors = $this->validationErrors($this->graphql('mutation { '.sprintf($template, 'f', $foreignId).' { id } }'), $field);
                $missingErrors = $this->validationErrors($this->graphql('mutation { '.sprintf($template, 'm', self::MISSING_ID).' { id } }'), $field);

                $this->assertNotNull($missingErrors, "{$field} via {$template}");
                $this->assertSame($missingErrors, $foreignErrors, "{$field} via {$template}");
            }
        }

        $this->assertDatabaseCount('products', 1);
        $this->assertNull($own->fresh()->category_id);
    }

    public function test_product_mutations_still_accept_an_own_category(): void
    {
        $category = ProductCategory::create([
            'organization_id' => $this->org->id, 'name' => 'Mine', 'slug' => 'mine', 'is_active' => true,
        ]);

        $response = $this->graphql("mutation { createProduct(sku: \"OWN-1\", name: \"Own\", category_id: {$category->id}) { id } }");

        $this->assertNull($response->json('errors'), (string) $response->getContent());
        $this->assertDatabaseHas('products', ['sku' => 'OWN-1', 'category_id' => $category->id]);
    }
}
