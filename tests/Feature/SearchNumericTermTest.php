<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\ListOrdersTool;
use App\Mcp\Tools\ListProductsTool;
use App\Mcp\Tools\ListPurchaseOrdersTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Support\Search;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A search term sent as a JSON number (a REST body, an MCP tool call) is
 * searched as text, as it was before the shared search helper, instead of
 * failing with a type error.
 */
final class SearchNumericTermTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $org = Organization::create(['name' => 'Acme', 'email' => 'acme@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@acme.test', 'password' => bcrypt('x'), 'organization_id' => $org->id, 'role' => 'admin']);

        Product::create(['organization_id' => $org->id, 'sku' => 'SKU-1001', 'name' => 'Widget 1001', 'price' => 10, 'stock' => 5]);
        Product::create(['organization_id' => $org->id, 'sku' => 'SKU-2002', 'name' => 'Gadget', 'price' => 10, 'stock' => 5]);
        $supplier = Supplier::create(['organization_id' => $org->id, 'name' => 'Supplier 1001', 'code' => 'S1', 'is_active' => true]);
        PurchaseOrder::create(['organization_id' => $org->id, 'supplier_id' => $supplier->id, 'po_number' => 'PO-1001', 'status' => 'draft',
            'order_date' => now()->toDateString(), 'currency' => 'USD', 'subtotal' => 0, 'total' => 0, 'created_by' => $this->admin->id]);
        foreach (['1001', '2002'] as $number) {
            Order::create(['organization_id' => $org->id, 'created_by' => $this->admin->id, 'order_number' => $number, 'customer_name' => 'Customer',
                'status' => 'pending', 'currency' => 'USD', 'order_date' => now()->toDateString(), 'subtotal' => 0, 'total' => 0]);
        }
    }

    public function test_the_helper_searches_a_number_as_text(): void
    {
        $this->assertSame(['Widget 1001'], Search::apply(Product::query(), ['name', 'sku'], 1001)->pluck('name')->all());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function apiIndexes(): array
    {
        return [
            'orders' => ['/api/v1/orders', '1001'],
            'products' => ['/api/v1/products', 'SKU-1001'],
            'purchase orders' => ['/api/v1/purchase-orders', 'PO-1001'],
        ];
    }

    #[DataProvider('apiIndexes')]
    public function test_a_rest_index_accepts_a_numeric_json_search(string $uri, string $expected): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->json('GET', $uri, ['search' => 1001])->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertStringContainsString($expected, $response->getContent());
    }

    public function test_mcp_list_tools_accept_a_numeric_search(): void
    {
        InventorosServer::actingAs($this->admin)->tool(ListOrdersTool::class, ['search' => 1001])->assertOk()->assertSee('1001')->assertDontSee('2002');
        InventorosServer::actingAs($this->admin)->tool(ListProductsTool::class, ['search' => 1001])->assertOk()->assertSee('Widget 1001')->assertDontSee('Gadget');
        InventorosServer::actingAs($this->admin)->tool(ListPurchaseOrdersTool::class, ['search' => 1001])->assertOk()->assertSee('PO-1001');
    }
}
