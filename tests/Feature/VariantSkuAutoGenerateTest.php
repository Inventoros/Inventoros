<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The variant SKU field says "Auto-generate" when left blank, so a blank SKU
 * must come back as a generated one (parent SKU + option values), unique
 * across the organization's product and variant SKUs.
 */
final class VariantSkuAutoGenerateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
    }

    private function storeTee(array $variants): Product
    {
        $this->actingAs($this->admin)
            ->post(route('products.store'), [
                'sku' => 'TEE',
                'name' => 'Tee',
                'price' => 20,
                'currency' => 'USD',
                'stock' => 0,
                'min_stock' => 0,
                'is_active' => true,
                'has_variants' => true,
                'options' => [['name' => 'Size', 'values' => ['Small', 'Extra Large']]],
                'variants' => $variants,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return Product::where('sku', 'TEE')->firstOrFail();
    }

    public function test_blank_variant_skus_are_generated_from_the_parent_and_options(): void
    {
        $tee = $this->storeTee([
            ['option_values' => ['Size' => 'Small'], 'sku' => ''],
            ['option_values' => ['Size' => 'Extra Large'], 'sku' => null],
        ]);

        $this->assertSame(
            ['TEE-EXTRA-LARGE', 'TEE-SMALL'],
            $tee->variants()->pluck('sku')->sort()->values()->all(),
        );
    }

    public function test_an_explicit_variant_sku_is_kept(): void
    {
        $tee = $this->storeTee([
            ['option_values' => ['Size' => 'Small'], 'sku' => 'MY-OWN'],
        ]);

        $this->assertSame(['MY-OWN'], $tee->variants()->pluck('sku')->all());
    }

    public function test_generated_skus_do_not_collide_with_existing_ones(): void
    {
        Product::create([
            'organization_id' => $this->org->id, 'sku' => 'TEE-SMALL', 'name' => 'Clash',
            'price' => 1, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0,
        ]);

        $tee = $this->storeTee([
            ['option_values' => ['Size' => 'Small']],
        ]);

        $this->assertSame(['TEE-SMALL-1'], $tee->variants()->pluck('sku')->all());
    }

    public function test_variants_created_directly_also_get_a_sku(): void
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'MUG', 'name' => 'Mug',
            'price' => 8, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'has_variants' => true,
        ]);

        $first = ProductVariant::create([
            'product_id' => $product->id, 'organization_id' => $this->org->id,
            'option_values' => ['Colour' => 'Blue'], 'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
        ]);
        $second = ProductVariant::create([
            'product_id' => $product->id, 'organization_id' => $this->org->id,
            'option_values' => ['Colour' => 'Blue'], 'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'position' => 1,
        ]);

        $this->assertSame('MUG-BLUE', $first->sku);
        $this->assertSame('MUG-BLUE-1', $second->sku);
    }
}
