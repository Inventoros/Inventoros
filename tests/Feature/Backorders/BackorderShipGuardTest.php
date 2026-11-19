<?php

declare(strict_types=1);

namespace Tests\Feature\Backorders;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\ShippingException;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Services\OrderService;
use App\Services\Shipping\ShipmentService;
use App\Support\BackorderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Shipping\ShippingTestHelpers;
use Tests\TestCase;

class BackorderShipGuardTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();

        $settings = $this->organization->settings ?? [];
        $settings[BackorderSettings::KEY] = ['enabled' => true, 'default_allow' => false];
        $this->organization->update(['settings' => $settings]);
    }

    private function ships(): ShipmentService
    {
        return app(ShipmentService::class);
    }

    private function backorderProduct(): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'BO-SHIP-'.uniqid(), 'name' => 'BO Ship Product', 'price' => 10, 'currency' => 'USD',
            'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'allow_backorder' => true,
        ]);
    }

    private function receiveStock(Product $product, int $quantity): StockAdjustment
    {
        return StockAdjustment::adjust($product->fresh(), $quantity, 'purchase', 'Received', null, null, actor: $this->admin);
    }

    public function test_cannot_create_shipment_while_backordered(): void
    {
        $order = $this->makeOrder([[$this->backorderProduct(), 5]]);
        $this->assertNotNull($order->backordered_at);

        try {
            $this->ships()->create($order, ['carrier' => 'manual', 'tracking_number' => 'T1'], $this->admin);
            $this->fail('A backordered order must not ship.');
        } catch (ShippingException $e) {
            $this->assertStringContainsStringIgnoringCase('backorder', $e->getMessage());
        }
    }

    public function test_a_normal_order_still_ships(): void
    {
        $order = $this->makeOrder();

        $shipment = $this->ships()->create($order, ['carrier' => 'manual', 'tracking_number' => 'T2'], $this->admin);

        $this->assertNotNull($shipment->id);
    }

    public function test_transition_to_shipped_blocked_while_backordered(): void
    {
        $order = $this->makeOrder([[$this->backorderProduct(), 5]]);

        try {
            app(OrderService::class)->transitionStatus($order->fresh(), OrderStatus::SHIPPED);
            $this->fail('A backordered order must not transition to shipped.');
        } catch (InvalidStateException $e) {
            $this->assertStringContainsStringIgnoringCase('backorder', $e->getMessage());
        }
    }

    public function test_direct_status_save_to_shipped_blocked_while_backordered(): void
    {
        // The web and REST order edits set the status on the model directly
        // rather than through transitionStatus(); the model backstop holds.
        $order = $this->makeOrder([[$this->backorderProduct(), 5]])->fresh();

        try {
            $order->update(['status' => OrderStatus::PROCESSING]);
            $order->update(['status' => OrderStatus::SHIPPED]);
            $this->fail('A backordered order must not save as shipped.');
        } catch (InvalidStateException $e) {
            $this->assertStringContainsStringIgnoringCase('backorder', $e->getMessage());
        }

        $this->assertNotSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_ship_allowed_after_full_reconcile(): void
    {
        $product = $this->backorderProduct();
        $order = $this->makeOrder([[$product, 5]]);

        // Stock arrives and reconciles automatically (stock_adjusted hook).
        $this->receiveStock($product, 5);
        $this->assertNull($order->fresh()->backordered_at);

        $shipment = $this->ships()->create($order->fresh(), ['carrier' => 'manual', 'tracking_number' => 'T3'], $this->admin);

        $this->assertNotNull($shipment->id);
    }
}
