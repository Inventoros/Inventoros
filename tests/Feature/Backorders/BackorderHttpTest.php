<?php

declare(strict_types=1);

namespace Tests\Feature\Backorders;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ProductLocationStockService;
use App\Support\BackorderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackorderHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'BO Http Org', 'email' => 'bo-http@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@bohttp.com', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $this->admin->forceFill(['role' => 'admin'])->save();

        $role = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator', 'description' => 'Full', 'is_system' => true,
                'permissions' => [
                    'view_products', 'create_products', 'edit_products',
                    'view_orders', 'create_orders', 'edit_orders', 'manage_stock',
                ],
            ]
        );
        $this->admin->roles()->syncWithoutDetaching([$role->id]);

        $settings = $this->organization->settings ?? [];
        $settings[BackorderSettings::KEY] = ['enabled' => true, 'default_allow' => false];
        $this->organization->update(['settings' => $settings]);
    }

    private function backorderProduct(): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'BO-'.uniqid(), 'name' => 'BO', 'price' => 10, 'currency' => 'USD',
            'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'allow_backorder' => true,
        ]);
    }

    private function backorder(Product $product, int $quantity): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 10.00]],
        ], $this->admin);
    }

    public function test_storing_a_product_persists_allow_backorder(): void
    {
        $this->actingAs($this->admin)
            ->post(route('products.store'), [
                'sku' => 'ALLOW-BO-1', 'name' => 'Backorderable', 'price' => 20, 'currency' => 'USD',
                'stock' => 0, 'min_stock' => 0, 'allow_backorder' => true,
            ])
            ->assertRedirect(route('products.index'));

        $this->assertTrue((bool) Product::where('sku', 'ALLOW-BO-1')->sole()->allow_backorder);
    }

    public function test_fulfill_backorder_action_reconciles_the_order(): void
    {
        $product = $this->backorderProduct();
        $order = $this->backorder($product, 3);

        // Stock becomes available without the hook, so the order stays
        // backordered until the action runs.
        $product->increment('stock', 3);
        app(ProductLocationStockService::class)->receive($product->fresh(), 3);

        $this->actingAs($this->admin)
            ->post(route('orders.fulfill-backorder', $order))
            ->assertRedirect();

        $this->assertSame(0, (int) $order->items()->first()->quantity_backordered);
        $this->assertNull($order->fresh()->backordered_at);
    }

    public function test_orders_index_filters_by_backordered(): void
    {
        $backordered = $this->backorder($this->backorderProduct(), 2);

        $inStock = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'IN-1', 'name' => 'In stock', 'price' => 10, 'currency' => 'USD',
            'stock' => 50, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->backorder($inStock, 1); // fully in stock, not backordered

        $this->actingAs($this->admin)
            ->get(route('orders.index', ['backordered' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $backordered->id));
    }

    public function test_rest_create_as_shipped_while_backordered_is_a_validation_error(): void
    {
        $product = $this->backorderProduct();
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Acme',
            'status' => 'shipped',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame(0, Order::count());
    }

    public function test_graphql_create_as_shipped_while_backordered_returns_the_reason(): void
    {
        $product = $this->backorderProduct();
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/graphql', ['query' => 'mutation { createOrder(customer_name: "Acme", status: "shipped", items: [{product_id: '.$product->id.', quantity: 2, unit_price: 10}]) { id } }']);

        $response->assertOk();
        $this->assertStringContainsString('backorder', (string) $response->json('errors.0.message'));
        $this->assertSame(0, Order::count());
    }
}
