<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreateOrderTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REST, GraphQL and MCP accept the same optional discounts as the web forms,
 * and all of them go through OrderService, so the server computes the totals.
 */
class OrderDiscountSurfacesTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Surface Org', 'email' => 'surface@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->organization->id, 'sku' => 'SURF-1', 'name' => 'Gadget',
            'price' => 20.00, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@surface.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);
    }

    public function test_rest_create_accepts_line_and_order_discounts(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'Api Customer',
            'discount_type' => 'fixed',
            'discount_value' => 5,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 20, 'tax' => 1.5, 'discount_type' => 'percent', 'discount_value' => 25],
            ],
        ])->assertCreated();

        // 40 gross - 10 line - 5 order + 1.50 tax
        $response->assertJsonPath('data.subtotal', '40.00')
            ->assertJsonPath('data.discount_amount', '15.00')
            ->assertJsonPath('data.discount_type', 'fixed')
            ->assertJsonPath('data.total', '26.50')
            ->assertJsonPath('data.items.0.discount_amount', '10.00')
            ->assertJsonPath('data.items.0.total', '31.50');
    }

    public function test_rest_create_rejects_an_oversized_discount_with_a_422(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Api Customer',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 20, 'discount_type' => 'fixed', 'discount_value' => 25],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.discount_value');

        $this->assertSame(0, Order::count());
    }

    public function test_rest_update_changes_the_order_discount_and_keeps_line_tax(): void
    {
        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now(),
            'shipping' => 3,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 20, 'tax' => 2]],
        ], $this->admin, 'api');

        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson("/api/v1/orders/{$order->id}", ['discount_type' => 'percent', 'discount_value' => 10])
            ->assertOk()
            ->assertJsonPath('data.discount_amount', '2.00')
            ->assertJsonPath('data.tax', '2.00')
            ->assertJsonPath('data.total', '23.00');

        // Removing it restores the undiscounted total.
        $this->putJson("/api/v1/orders/{$order->id}", ['discount_type' => null, 'discount_value' => null])
            ->assertOk()
            ->assertJsonPath('data.discount_amount', '0.00')
            ->assertJsonPath('data.total', '25.00');
    }

    public function test_graphql_create_accepts_discounts_and_exposes_them(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $query = sprintf(
            'mutation { createOrder(customer_name: "Gql", discount_type: "percent", discount_value: 50, items: [{product_id: %d, quantity: 1, unit_price: 20, discount_type: "fixed", discount_value: 4}]) { subtotal discount_type discount_amount total items { discount_amount total } } }',
            $this->product->id,
        );

        $this->postJson('/graphql', ['query' => $query])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.createOrder.subtotal', 20)
            ->assertJsonPath('data.createOrder.discount_type', 'percent')
            ->assertJsonPath('data.createOrder.discount_amount', 12)
            ->assertJsonPath('data.createOrder.total', 8)
            ->assertJsonPath('data.createOrder.items.0.discount_amount', 4);
    }

    public function test_graphql_create_reports_an_invalid_discount_as_an_error(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $query = sprintf(
            'mutation { createOrder(customer_name: "Gql", items: [{product_id: %d, quantity: 1, unit_price: 20, discount_type: "percent", discount_value: 150}]) { id } }',
            $this->product->id,
        );

        $this->postJson('/graphql', ['query' => $query])->assertJsonPath('errors.0.message', 'A percentage discount cannot exceed 100%.');
        $this->assertSame(0, Order::count());
    }

    public function test_graphql_update_changes_the_order_discount(): void
    {
        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 20]],
        ], $this->admin, 'graphql');

        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/graphql', ['query' => sprintf('mutation { updateOrder(id: %d, discount_type: "fixed", discount_value: 7.5) { discount_amount total } }', $order->id)])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.updateOrder.discount_amount', 7.5)
            ->assertJsonPath('data.updateOrder.total', 12.5);
    }

    public function test_mcp_create_order_accepts_discounts(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(CreateOrderTool::class, [
                'customer_name' => 'Mcp Customer',
                'discount_type' => 'fixed',
                'discount_value' => 1,
                'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 20, 'discount_type' => 'percent', 'discount_value' => 10]],
            ])
            ->assertOk()
            ->assertSee('discount_amount');

        $order = Order::firstOrFail();
        $this->assertSame('3.00', (string) $order->discount_amount);
        $this->assertSame('17.00', (string) $order->total);
    }
}
