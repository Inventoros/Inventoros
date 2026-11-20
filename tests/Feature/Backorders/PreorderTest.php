<?php

declare(strict_types=1);

namespace Tests\Feature\Backorders;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateException;
use App\Exceptions\ShippingException;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Role;
use App\Services\OrderService;
use App\Services\Shipping\ShipmentService;
use App\Support\BackorderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Shipping\ShippingTestHelpers;
use Tests\TestCase;

/**
 * Preorders: a product with a future release date can be ordered past stock
 * while backorders are on, and an order holding it cannot ship before the
 * release date even when the stock is there.
 */
class PreorderTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
        $this->backorders(true);

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
    }

    private function backorders(bool $enabled): void
    {
        $settings = $this->organization->settings ?? [];
        $settings[BackorderSettings::KEY] = ['enabled' => $enabled, 'default_allow' => false];
        $this->organization->update(['settings' => $settings]);
    }

    private function preorderProduct(int $stock = 0, ?string $releaseDate = null): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'PRE-'.uniqid(), 'name' => 'Preorder Product', 'price' => 10, 'currency' => 'USD',
            'stock' => $stock, 'min_stock' => 0, 'is_active' => true,
            'release_date' => $releaseDate ?? now()->addDays(10)->toDateString(),
        ]);
    }

    public function test_a_preorder_can_be_ordered_past_stock_without_allow_backorder(): void
    {
        $product = $this->preorderProduct(stock: 1);
        $this->assertTrue($product->isPreorder());
        $this->assertFalse((bool) $product->allow_backorder);

        $order = $this->makeOrder([[$product, 3]]);

        $this->assertNotNull($order->backordered_at);
        $this->assertSame(2, (int) $order->items->first()->quantity_backordered);
        $this->assertSame(0, (int) $product->fresh()->stock);
    }

    public function test_a_released_product_is_no_longer_a_preorder(): void
    {
        $product = $this->preorderProduct(stock: 0, releaseDate: now()->toDateString());
        $this->assertFalse($product->isPreorder());

        $this->expectException(InsufficientStockException::class);
        $this->makeOrder([[$product, 1]]);
    }

    public function test_preorders_follow_the_organization_backorder_switch(): void
    {
        $this->backorders(false);
        $product = $this->preorderProduct(stock: 0);

        $this->expectException(InsufficientStockException::class);
        $this->makeOrder([[$product, 1]]);
    }

    public function test_a_preorder_cannot_ship_before_release_even_with_stock(): void
    {
        $product = $this->preorderProduct(stock: 10);
        $order = $this->makeOrder([[$product, 2], [$this->widget, 1]]);
        $this->assertNull($order->backordered_at);
        $this->assertSame($product->release_date->toDateString(), $order->awaitingReleaseUntil()?->toDateString());

        try {
            app(ShipmentService::class)->create($order, ['carrier' => 'manual', 'tracking_number' => 'P1'], $this->admin);
            $this->fail('A preorder must not ship before its release date.');
        } catch (ShippingException $e) {
            $this->assertStringContainsString($product->release_date->toDateString(), $e->getMessage());
        }

        try {
            app(OrderService::class)->transitionStatus($order->fresh(), OrderStatus::SHIPPED);
            $this->fail('A preorder must not transition to shipped before release.');
        } catch (InvalidStateException $e) {
            $this->assertSame(Order::PREORDER_HOLD_MESSAGE, $e->getMessage());
        }

        $fresh = $order->fresh();
        $fresh->update(['status' => OrderStatus::PROCESSING]);
        try {
            $fresh->update(['status' => OrderStatus::SHIPPED]);
            $this->fail('A preorder must not save as shipped before release.');
        } catch (InvalidStateException $e) {
            $this->assertSame(Order::PREORDER_HOLD_MESSAGE, $e->getMessage());
        }
        $this->assertNotSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_the_order_ships_once_the_release_date_arrives(): void
    {
        $product = $this->preorderProduct(stock: 10, releaseDate: now()->addDays(3)->toDateString());
        $order = $this->makeOrder([[$product, 2]]);

        $this->travel(3)->days();

        $this->assertNull($order->fresh()->awaitingReleaseUntil());
        $shipment = app(ShipmentService::class)->create($order->fresh(), ['carrier' => 'manual', 'tracking_number' => 'P2'], $this->admin);
        $this->assertNotNull($shipment->id);
    }

    public function test_the_latest_release_date_holds_the_order(): void
    {
        $soon = $this->preorderProduct(stock: 5, releaseDate: now()->addDays(2)->toDateString());
        $later = $this->preorderProduct(stock: 5, releaseDate: now()->addDays(20)->toDateString());
        $order = $this->makeOrder([[$soon, 1], [$later, 1]]);

        $this->assertSame($later->release_date->toDateString(), $order->awaitingReleaseUntil()?->toDateString());
        // Same answer from the loaded relations as from the query.
        $order->load('items.product');
        $this->assertSame($later->release_date->toDateString(), $order->awaitingReleaseUntil()?->toDateString());
    }

    public function test_an_order_with_a_preorder_cannot_be_created_as_shipped(): void
    {
        $product = $this->preorderProduct(stock: 10);

        $this->expectException(InvalidStateException::class);
        $this->makeOrder([[$product, 1]], ['status' => 'shipped']);
    }

    public function test_rest_accepts_and_returns_the_release_date(): void
    {
        Sanctum::actingAs($this->admin);
        $date = now()->addDays(30)->toDateString();

        $this->postJson('/api/v1/products', [
            'sku' => 'PRE-API', 'name' => 'API Preorder', 'price' => 10, 'currency' => 'USD',
            'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'release_date' => $date,
        ])->assertStatus(201)
            ->assertJsonPath('data.release_date', $date)
            ->assertJsonPath('data.is_preorder', true);

        $this->postJson('/api/v1/products', [
            'sku' => 'PRE-BAD', 'name' => 'Bad date', 'price' => 10, 'currency' => 'USD',
            'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'release_date' => 'soon',
        ])->assertStatus(422)->assertJsonValidationErrors('release_date');
    }

    public function test_rest_create_as_shipped_with_a_preorder_is_a_validation_error(): void
    {
        $product = $this->preorderProduct(stock: 10);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Acme',
            'status' => 'shipped',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_the_order_page_shows_the_release_hold(): void
    {
        $product = $this->preorderProduct(stock: 10);
        $order = $this->makeOrder([[$product, 1]]);

        $this->actingAs($this->admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Show')
                ->where('order.preorder_release_date', $product->release_date->toDateString())
                ->where('order.items.0.preorder_release_date', $product->release_date->toDateString()));
    }
}
