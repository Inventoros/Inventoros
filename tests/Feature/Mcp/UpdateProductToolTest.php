<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\UpdateProductTool;
use App\Models\Inventory\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * update_product (#462): edits only the fields sent, through ProductService,
 * gated on edit_products, scoped to the token's organization, and never
 * writes on-hand stock.
 */
class UpdateProductToolTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
    }

    public function test_it_changes_only_the_fields_sent(): void
    {
        $org = $this->makeOrganization('Acme');
        $product = $this->makeProduct($org, ['name' => 'Widget', 'purchase_price' => 4.00, 'min_stock' => 3]);

        InventorosServer::actingAs($this->makeMember($org, ['edit_products']))
            ->tool(UpdateProductTool::class, ['id' => $product->id, 'purchase_price' => 4.75, 'tax_rate' => 27])
            ->assertOk()
            ->assertSee('Product updated.')
            ->assertSee('purchase_price');

        $fresh = $product->fresh();
        $this->assertEquals(4.75, (float) $fresh->purchase_price);
        $this->assertEquals(27, (float) $fresh->tax_rate);
        $this->assertSame('Widget', $fresh->name);
        $this->assertSame(3, (int) $fresh->min_stock);
        $this->assertSame(50, (int) $fresh->stock);
    }

    public function test_it_needs_edit_products(): void
    {
        $org = $this->makeOrganization('Acme');
        $product = $this->makeProduct($org, ['price' => 10]);

        InventorosServer::actingAs($this->makeMember($org, ['view_products', 'create_products']))
            ->tool(UpdateProductTool::class, ['id' => $product->id, 'price' => 99])
            ->assertHasErrors(['edit_products']);

        $this->assertEquals(10, (float) $product->fresh()->price);
    }

    public function test_it_cannot_reach_another_organizations_product(): void
    {
        $mine = $this->makeOrganization('Mine');
        $theirs = $this->makeProduct($this->makeOrganization('Theirs'), ['price' => 10]);

        InventorosServer::actingAs($this->makeMember($mine, ['edit_products']))
            ->tool(UpdateProductTool::class, ['id' => $theirs->id, 'price' => 1])
            ->assertHasErrors(['Product not found']);

        $this->assertEquals(10, (float) $theirs->fresh()->price);
    }

    public function test_it_refuses_stock_and_points_to_adjust_stock(): void
    {
        $org = $this->makeOrganization('Acme');
        $product = $this->makeProduct($org, ['stock' => 50]);

        InventorosServer::actingAs($this->makeMember($org, ['edit_products']))
            ->tool(UpdateProductTool::class, ['id' => $product->id, 'stock' => 500])
            ->assertHasErrors(['adjust_stock']);

        $this->assertSame(50, (int) $product->fresh()->stock);
    }

    public function test_it_refuses_fields_it_does_not_edit(): void
    {
        $org = $this->makeOrganization('Acme');
        $product = $this->makeProduct($org);

        InventorosServer::actingAs($this->makeMember($org, ['edit_products']))
            ->tool(UpdateProductTool::class, ['id' => $product->id, 'organization_id' => 999, 'variants' => []])
            ->assertHasErrors(['organization_id', 'variants']);

        $this->assertSame($org->id, $product->fresh()->organization_id);
    }

    public function test_the_sku_must_stay_unique_in_the_organization(): void
    {
        $org = $this->makeOrganization('Acme');
        $this->makeProduct($org, ['sku' => 'TAKEN']);
        $product = $this->makeProduct($org, ['sku' => 'MINE']);

        InventorosServer::actingAs($this->makeMember($org, ['edit_products']))
            ->tool(UpdateProductTool::class, ['id' => $product->id, 'sku' => 'TAKEN'])
            ->assertHasErrors();

        $this->assertSame('MINE', $product->fresh()->sku);
    }

    public function test_it_needs_at_least_one_field(): void
    {
        $org = $this->makeOrganization('Acme');
        $product = $this->makeProduct($org);

        InventorosServer::actingAs($this->makeMember($org, ['edit_products']))
            ->tool(UpdateProductTool::class, ['id' => $product->id])
            ->assertHasErrors(['at least one field']);
    }

    public function test_a_category_from_another_organization_is_refused(): void
    {
        $org = $this->makeOrganization('Acme');
        $other = $this->makeOrganization('Other');
        $foreign = ProductCategory::create([
            'organization_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'is_active' => true,
        ]);
        $product = $this->makeProduct($org);

        InventorosServer::actingAs($this->makeMember($org, ['edit_products']))
            ->tool(UpdateProductTool::class, ['id' => $product->id, 'category_id' => $foreign->id])
            ->assertHasErrors();

        $this->assertNull($product->fresh()->category_id);
    }
}
