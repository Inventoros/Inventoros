<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\WebhookDeliveryJob;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Verifies every advertised webhook lifecycle event actually reaches a
 * subscribed webhook (a WebhookDeliveryJob is pushed) when the underlying
 * model/service operation occurs. These hooks were registered by
 * WebhookEventSubscriber but most were never fired by do_action.
 */
final class WebhookLifecycleEventsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Hook Org',
            'email' => 'hook@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'HOOK-001',
            'name' => 'Hook Product',
            'price' => 10.00,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 5,
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'organization_id' => $this->org->id,
            'name' => 'Hook Supplier',
            'email' => 'supplier@hook.com',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@hook.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->org->id,
            'role' => 'admin',
        ]);

        // StockAdjustment records auth()->id(); several hooks pass auth()->user().
        $this->actingAs($this->admin);
    }

    private function subscribe(string $dottedEvent): Webhook
    {
        return Webhook::create([
            'organization_id' => $this->org->id,
            'name' => 'Hook '.$dottedEvent,
            'url' => 'https://example.com/hook',
            'secret' => 'shh',
            'events' => [$dottedEvent],
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    private function makeOrder(): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Customer',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 5.00],
            ],
        ], $this->admin, 'manual');
    }

    public function test_stock_adjusted_dispatches(): void
    {
        $this->subscribe('stock.adjusted');
        Queue::fake();

        StockAdjustment::adjust($this->product, -1, 'manual', 'test');

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function test_out_of_stock_alert_dispatches(): void
    {
        $this->product->update(['stock' => 1, 'min_stock' => 0]);
        $this->subscribe('product.out_of_stock');
        Queue::fake();

        StockAdjustment::adjust($this->product->fresh(), -1, 'manual', 'drain');

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function test_low_stock_alert_dispatches(): void
    {
        // Stock 10, min_stock 5 -> drop to 4 crosses the low-stock threshold.
        $this->product->update(['stock' => 10, 'min_stock' => 5]);
        $this->subscribe('product.low_stock');
        Queue::fake();

        StockAdjustment::adjust($this->product->fresh(), -6, 'manual', 'low');

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function test_order_updated_dispatches(): void
    {
        $order = $this->makeOrder();
        $this->subscribe('order.updated');
        Queue::fake();

        $order->update(['notes' => 'changed']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function test_order_status_changed_dispatches(): void
    {
        $order = $this->makeOrder();
        $this->subscribe('order.status_changed');
        Queue::fake();

        $order->update(['status' => OrderStatus::PROCESSING]);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function test_order_approved_dispatches(): void
    {
        $order = $this->makeOrder();
        $this->subscribe('order.approved');
        Queue::fake();

        $order->update(['approval_status' => 'approved']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function test_order_rejected_dispatches(): void
    {
        $order = $this->makeOrder();
        $this->subscribe('order.rejected');
        Queue::fake();

        $order->update(['approval_status' => 'rejected']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function test_purchase_order_created_dispatches(): void
    {
        $this->subscribe('purchase_order.created');
        Queue::fake();

        PurchaseOrder::create([
            'organization_id' => $this->org->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-'.uniqid(),
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'subtotal' => 500.00,
            'tax' => 50.00,
            'total' => 550.00,
            'currency' => 'USD',
            'created_by' => $this->admin->id,
        ]);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function test_purchase_order_received_dispatches(): void
    {
        $po = PurchaseOrder::create([
            'organization_id' => $this->org->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-'.uniqid(),
            'status' => PurchaseOrder::STATUS_SENT,
            'order_date' => now()->toDateString(),
            'subtotal' => 500.00,
            'tax' => 50.00,
            'total' => 550.00,
            'currency' => 'USD',
            'created_by' => $this->admin->id,
        ]);

        $this->subscribe('purchase_order.received');
        Queue::fake();

        $po->update(['status' => PurchaseOrder::STATUS_RECEIVED]);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function test_purchase_order_cancelled_dispatches(): void
    {
        $po = PurchaseOrder::create([
            'organization_id' => $this->org->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-'.uniqid(),
            'status' => PurchaseOrder::STATUS_SENT,
            'order_date' => now()->toDateString(),
            'subtotal' => 500.00,
            'tax' => 50.00,
            'total' => 550.00,
            'currency' => 'USD',
            'created_by' => $this->admin->id,
        ]);

        $this->subscribe('purchase_order.cancelled');
        Queue::fake();

        $po->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }
}
