<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Resources\LowStockResource;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\AdjustStockTool;
use App\Mcp\Tools\GetProductTool;
use App\Mcp\Tools\ListLowStockTool;
use App\Mcp\Tools\ListProductsTool;
use App\Mcp\Tools\LookupBarcodeTool;
use App\Mcp\Tools\SearchProductsTool;
use App\Mcp\Tools\WhoAmITool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventorosMcpServerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $viewer;

    protected User $strangerOrgAdmin;

    protected Organization $organization;

    protected Organization $otherOrganization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Org',
            'email' => 'test@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->otherOrganization = Organization::create([
            'name' => 'Other Org',
            'email' => 'other@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => [
                    'view_products', 'manage_products',
                    'view_orders', 'manage_orders',
                    'view_suppliers', 'manage_suppliers',
                    'view_purchase_orders', 'manage_purchase_orders',
                    'edit_purchase_orders', 'receive_purchase_orders',
                    'view_warehouses', 'manage_warehouses',
                    'view_locations', 'manage_locations',
                    'view_categories', 'manage_categories',
                    'manage_stock', 'view_stock_adjustments',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            ['name' => 'Viewer', 'is_system' => true, 'permissions' => ['view_products']]
        );

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);
        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $this->viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);
        $this->viewer->roles()->syncWithoutDetaching([$viewerRole->id]);

        $this->strangerOrgAdmin = User::create([
            'name' => 'Stranger',
            'email' => 'stranger@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->otherOrganization->id,
            'role' => 'admin',
        ]);
        $this->strangerOrgAdmin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-'.uniqid(),
            'name' => 'Widget',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 5,
            'is_active' => true,
            'tracking_type' => 'none',
        ], $attributes));
    }

    public function test_unauthenticated_call_is_rejected(): void
    {
        $response = InventorosServer::tool(WhoAmITool::class);
        $response->assertHasErrors(['Sanctum']);
    }

    public function test_whoami_returns_authenticated_user(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(WhoAmITool::class)
            ->assertOk()
            ->assertSee($this->admin->email)
            ->assertSee((string) $this->organization->id);
    }

    public function test_list_products_returns_only_caller_organization_products(): void
    {
        $this->product(['name' => 'My Widget']);
        $foreign = $this->product(['organization_id' => $this->otherOrganization->id, 'name' => 'Their Widget']);

        $response = InventorosServer::actingAs($this->admin)
            ->tool(ListProductsTool::class)
            ->assertOk();

        $response->assertSee('My Widget');
        $response->assertDontSee('Their Widget');
    }

    public function test_list_products_allows_viewer_with_view_products_permission(): void
    {
        $this->product(['name' => 'Restricted']);

        InventorosServer::actingAs($this->viewer)
            ->tool(ListProductsTool::class)
            ->assertOk();
    }

    public function test_list_products_rejects_user_with_no_role(): void
    {
        $unprivilegedUser = User::create([
            'name' => 'Nobody',
            'email' => 'nobody@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $this->product(['name' => 'Restricted']);

        InventorosServer::actingAs($unprivilegedUser)
            ->tool(ListProductsTool::class)
            ->assertHasErrors(['view_products']);
    }

    public function test_get_product_returns_404_style_error_for_other_org_product(): void
    {
        $foreign = $this->product([
            'organization_id' => $this->otherOrganization->id,
            'name' => 'Foreign',
        ]);

        InventorosServer::actingAs($this->admin)
            ->tool(GetProductTool::class, ['id' => $foreign->id])
            ->assertHasErrors(['not found']);
    }

    public function test_search_products_finds_substring_match(): void
    {
        $this->product(['name' => 'Blue Widget', 'sku' => 'BLU-001']);
        $this->product(['name' => 'Red Gadget', 'sku' => 'RED-001']);

        InventorosServer::actingAs($this->admin)
            ->tool(SearchProductsTool::class, ['query' => 'Widget'])
            ->assertOk()
            ->assertSee('Blue Widget')
            ->assertDontSee('Red Gadget');
    }

    public function test_search_products_validates_required_query(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(SearchProductsTool::class, [])
            ->assertHasErrors();
    }

    public function test_lookup_barcode_finds_by_exact_barcode(): void
    {
        $this->product(['name' => 'Scanned Item', 'barcode' => '0123456789012']);

        InventorosServer::actingAs($this->admin)
            ->tool(LookupBarcodeTool::class, ['code' => '0123456789012'])
            ->assertOk()
            ->assertSee('Scanned Item')
            ->assertSee('product');
    }

    public function test_lookup_barcode_does_not_leak_other_org_products(): void
    {
        $this->product([
            'organization_id' => $this->otherOrganization->id,
            'name' => 'Leaked',
            'barcode' => 'LEAKY-CODE',
        ]);

        InventorosServer::actingAs($this->admin)
            ->tool(LookupBarcodeTool::class, ['code' => 'LEAKY-CODE'])
            ->assertHasErrors(['No product or variant found']);
    }

    public function test_list_low_stock_only_includes_at_or_below_min(): void
    {
        $this->product(['name' => 'Healthy', 'stock' => 100, 'min_stock' => 10]);
        $this->product(['name' => 'Critical', 'stock' => 2, 'min_stock' => 5]);

        InventorosServer::actingAs($this->admin)
            ->tool(ListLowStockTool::class)
            ->assertOk()
            ->assertSee('Critical')
            ->assertDontSee('Healthy');
    }

    public function test_adjust_stock_modifies_on_hand_count(): void
    {
        $product = $this->product(['stock' => 20]);

        InventorosServer::actingAs($this->admin)
            ->tool(AdjustStockTool::class, [
                'product_id' => $product->id,
                'quantity' => -3,
                'type' => 'damage',
                'reason' => 'Dropped pallet',
            ])
            ->assertOk();

        $this->assertSame(17, $product->fresh()->stock);
    }

    public function test_adjust_stock_rejects_oversell(): void
    {
        $product = $this->product(['stock' => 5]);

        InventorosServer::actingAs($this->admin)
            ->tool(AdjustStockTool::class, [
                'product_id' => $product->id,
                'quantity' => -100,
                'type' => 'damage',
            ])
            ->assertHasErrors(['Cannot remove']);

        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_adjust_stock_rejects_other_org_product(): void
    {
        $foreign = $this->product([
            'organization_id' => $this->otherOrganization->id,
            'stock' => 50,
        ]);

        InventorosServer::actingAs($this->admin)
            ->tool(AdjustStockTool::class, [
                'product_id' => $foreign->id,
                'quantity' => -1,
                'type' => 'damage',
            ])
            ->assertHasErrors(['not found']);

        $this->assertSame(50, $foreign->fresh()->stock);
    }

    public function test_adjust_stock_requires_manage_stock_permission(): void
    {
        $product = $this->product(['stock' => 20]);

        // viewer only has view_products
        InventorosServer::actingAs($this->viewer)
            ->tool(AdjustStockTool::class, [
                'product_id' => $product->id,
                'quantity' => -1,
                'type' => 'damage',
            ])
            ->assertHasErrors(['manage_stock']);
    }

    public function test_low_stock_resource_returns_low_stock_products(): void
    {
        $this->product(['name' => 'OK', 'stock' => 100, 'min_stock' => 10]);
        $this->product(['name' => 'BadStock', 'stock' => 1, 'min_stock' => 5]);

        InventorosServer::actingAs($this->admin)
            ->resource(LowStockResource::class)
            ->assertOk()
            ->assertSee('BadStock')
            ->assertDontSee('OK');
    }

    public function test_http_endpoint_requires_bearer_token(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $response->assertStatus(401);
    }
}
