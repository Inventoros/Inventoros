<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A variant's SKU and barcode find its product everywhere a product's own
 * codes do: the barcode lookup (which also names the variant), the product
 * index search, and the global search.
 */
final class VariantSearchAndBarcodeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $shirt;

    private ProductVariant $small;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Shop', 'email' => 'shop@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@shop.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->shirt = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SHIRT', 'barcode' => '1000000000001',
            'name' => 'Oxford Shirt', 'price' => 20, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0,
            'is_active' => true, 'has_variants' => true,
        ]);
        $this->small = ProductVariant::create([
            'product_id' => $this->shirt->id, 'organization_id' => $this->org->id,
            'sku' => 'OXF-SM-BLU', 'barcode' => '2000000000002', 'title' => 'Small / Blue',
            'option_values' => ['Size' => 'S'], 'price' => 21, 'stock' => 6, 'min_stock' => 0,
            'is_active' => true, 'position' => 0,
        ]);

        Product::create([
            'organization_id' => $this->org->id, 'sku' => 'MUG', 'name' => 'Mug', 'price' => 8,
            'currency' => 'USD', 'stock' => 3, 'min_stock' => 0, 'is_active' => true,
        ]);
    }

    // ==================== BARCODE LOOKUP ====================

    public function test_a_variant_barcode_resolves_to_its_product_and_variant(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/barcode/2000000000002')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('product.id', $this->shirt->id)
            ->assertJsonPath('variant.id', $this->small->id)
            ->assertJsonPath('variant.title', 'Small / Blue')
            ->assertJsonPath('variant.sku', 'OXF-SM-BLU')
            ->assertJsonPath('variant.stock', 6);
    }

    public function test_a_variant_sku_resolves_to_its_product_and_variant(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/barcode/OXF-SM-BLU')
            ->assertOk()
            ->assertJsonPath('product.id', $this->shirt->id)
            ->assertJsonPath('variant.id', $this->small->id);
    }

    public function test_a_product_code_still_resolves_without_a_variant(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/barcode/1000000000001')
            ->assertOk()
            ->assertJsonPath('product.id', $this->shirt->id)
            ->assertJsonPath('variant', null);
    }

    public function test_another_organizations_variant_barcode_is_not_found(): void
    {
        $other = Organization::create([
            'name' => 'Other', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $foreign = Product::create([
            'organization_id' => $other->id, 'sku' => 'F', 'name' => 'Foreign', 'price' => 1,
            'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'has_variants' => true,
        ]);
        ProductVariant::create([
            'product_id' => $foreign->id, 'organization_id' => $other->id, 'sku' => 'F-1',
            'barcode' => '3000000000003', 'title' => 'One', 'option_values' => ['Size' => 'One'], 'price' => 1, 'stock' => 1, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/barcode/3000000000003')->assertNotFound();
    }

    // ==================== PRODUCT INDEX SEARCH ====================

    public function test_product_index_search_matches_a_variant_sku(): void
    {
        $this->actingAs($this->admin)
            ->get(route('products.index', ['search' => 'OXF-SM']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Index')
                ->has('products.data', 1)
                ->where('products.data.0.id', $this->shirt->id)
            );
    }

    public function test_product_index_search_matches_a_variant_barcode(): void
    {
        $this->actingAs($this->admin)
            ->get(route('products.index', ['search' => '2000000000002']))
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.id', $this->shirt->id)
            );
    }

    // ==================== GLOBAL SEARCH ====================

    public function test_global_search_matches_a_variant_barcode_and_names_the_variant(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('search', ['q' => '2000000000002']))
            ->assertOk();

        $products = $response->json('products');
        $this->assertCount(1, $products);
        $this->assertSame($this->shirt->id, $products[0]['id']);
        $this->assertStringContainsString('OXF-SM-BLU', $products[0]['subtitle']);
    }

    public function test_global_search_matches_a_variant_sku_case_insensitively(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('search', ['q' => 'oxf-sm']))
            ->assertOk();

        $this->assertSame([$this->shirt->id], collect($response->json('products'))->pluck('id')->all());
    }
}
