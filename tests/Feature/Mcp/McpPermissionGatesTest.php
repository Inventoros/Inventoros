<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\AdjustStockTool;
use App\Mcp\Tools\CreateProductTool;
use App\Mcp\Tools\ListLocationsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * MCP tools are gated on the same real permissions as the REST routes. The
 * tools used to accept strings that are not permissions (manage_products,
 * view_locations, view_stock_adjustments), which no role template grants, so
 * a user holding the real permission was refused and a hand-written role
 * holding the fake one was let in.
 */
class McpPermissionGatesTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    public function test_creating_a_product_needs_create_products(): void
    {
        $this->markInstalled();
        $org = $this->makeOrganization('Acme');

        InventorosServer::actingAs($this->makeMember($org, ['create_products']))
            ->tool(CreateProductTool::class, ['sku' => 'MCP-1', 'name' => 'Made over MCP'])
            ->assertOk();
        $this->assertDatabaseHas('products', ['sku' => 'MCP-1', 'organization_id' => $org->id]);
    }

    public function test_the_old_manage_products_string_cannot_create_a_product(): void
    {
        $this->markInstalled();
        $org = $this->makeOrganization('Acme');

        InventorosServer::actingAs($this->makeMember($org, ['manage_products']))
            ->tool(CreateProductTool::class, ['sku' => 'MCP-2', 'name' => 'Nope'])
            ->assertHasErrors();
        $this->assertDatabaseMissing('products', ['sku' => 'MCP-2']);
    }

    public function test_phantom_strings_open_no_tool(): void
    {
        $this->markInstalled();
        $org = $this->makeOrganization('Acme');
        $product = $this->makeProduct($org, ['stock' => 10]);
        $phantom = $this->makeMember($org, ['view_locations', 'view_stock_adjustments', 'manage_products']);

        InventorosServer::actingAs($phantom)->tool(ListLocationsTool::class, [])->assertHasErrors();
        InventorosServer::actingAs($phantom)
            ->tool(AdjustStockTool::class, ['product_id' => $product->id, 'quantity' => 1, 'type' => 'manual', 'reason' => 'x'])
            ->assertHasErrors();

        $this->assertSame(10, $product->fresh()->stock);
    }
}
