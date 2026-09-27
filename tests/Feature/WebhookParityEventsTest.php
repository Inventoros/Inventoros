<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\WorkOrder;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\OrderService;
use App\Services\ReturnOrderService;
use App\Services\StockAuditService;
use App\Services\StockTransferService;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The customer, return, transfer, work-order and stock-audit webhook events
 * fire from model observers after commit, so every surface (web, REST,
 * GraphQL, MCP) triggers them and a rolled-back change never does.
 */
final class WebhookParityEventsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Hook Org', 'email' => 'h@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@hook.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'H-1', 'name' => 'Hook Product',
            'price' => 10, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 1, 'is_active' => true,
        ]);

        $this->actingAs($this->admin);
    }

    private function subscribe(string $event): void
    {
        Webhook::create([
            'organization_id' => $this->org->id,
            'name' => 'Hook '.$event,
            'url' => 'https://example.com/hook',
            'events' => [$event],
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed> the delivered `data` payload
     */
    private function assertDelivered(string $event): array
    {
        $deliveries = WebhookDelivery::where('event', $event)->get();
        $this->assertCount(1, $deliveries, "Expected exactly one {$event} delivery.");

        return $deliveries->first()->payload['data'];
    }

    public function test_new_events_are_advertised_and_grouped(): void
    {
        $events = ['customer.created', 'customer.updated', 'customer.deleted', 'return.created', 'return.received',
            'transfer.created', 'transfer.completed', 'work_order.completed', 'stock_audit.completed'];

        foreach ($events as $event) {
            $this->assertContains($event, WebhookService::availableEvents());
            $this->assertNotNull(WebhookService::getEventDescription($event), "{$event} has no UI description");
        }
    }

    public function test_customer_lifecycle_events(): void
    {
        foreach (['customer.created', 'customer.updated', 'customer.deleted'] as $event) {
            $this->subscribe($event);
        }

        $customer = Customer::create(['organization_id' => $this->org->id, 'name' => 'Acme Ltd', 'email' => 'a@acme.test']);
        $this->assertSame('Acme Ltd', $this->assertDelivered('customer.created')['customer']['name']);

        $customer->update(['name' => 'Acme Inc']);
        $this->assertSame('Acme Inc', $this->assertDelivered('customer.updated')['customer']['name']);

        $customer->delete();
        $this->assertSame($customer->id, $this->assertDelivered('customer.deleted')['customer']['id']);
    }

    public function test_rolled_back_customer_create_does_not_fire(): void
    {
        $this->subscribe('customer.created');

        try {
            DB::transaction(function () {
                Customer::create(['organization_id' => $this->org->id, 'name' => 'Ghost']);
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, WebhookDelivery::where('event', 'customer.created')->count());
    }

    public function test_return_created_and_received_events(): void
    {
        $this->subscribe('return.created');
        $this->subscribe('return.received');

        $order = app(OrderService::class)->create([
            'customer_name' => 'Buyer', 'status' => 'delivered', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 10]],
        ], $this->admin);

        $returns = app(ReturnOrderService::class);
        $return = $returns->create($this->org->id, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Broken',
            'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 1, 'condition' => 'damaged', 'restock' => false]],
        ]);

        $payload = $this->assertDelivered('return.created');
        $this->assertSame($return->return_number, $payload['return']['return_number']);
        $this->assertCount(1, $payload['return']['items']);

        $returns->approve($return, $this->admin);
        $this->assertSame(0, WebhookDelivery::where('event', 'return.received')->count());

        $returns->receive($return->fresh(), $this->admin);
        $this->assertSame('received', $this->assertDelivered('return.received')['return']['status']);
    }

    public function test_transfer_created_and_completed_events(): void
    {
        $this->subscribe('transfer.created');
        $this->subscribe('transfer.completed');

        $from = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'A', 'code' => 'A', 'is_active' => true]);
        $to = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'B', 'code' => 'B', 'is_active' => true]);
        $this->product->update(['location_id' => $from->id]);

        $transfers = app(StockTransferService::class);
        $transfer = $transfers->create($this->org->id, $this->admin, [
            'from_location_id' => $from->id, 'to_location_id' => $to->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5]],
        ]);

        $this->assertSame($transfer->transfer_number, $this->assertDelivered('transfer.created')['transfer']['transfer_number']);

        $transfers->complete($transfer, $this->admin);
        $payload = $this->assertDelivered('transfer.completed');
        $this->assertSame('completed', $payload['transfer']['status']);
        $this->assertSame(5, $payload['transfer']['items'][0]['quantity']);
    }

    public function test_work_order_completed_event(): void
    {
        $this->subscribe('work_order.completed');

        $workOrder = WorkOrder::create([
            'organization_id' => $this->org->id, 'product_id' => $this->product->id, 'created_by' => $this->admin->id,
            'work_order_number' => 'WO-1', 'quantity' => 2, 'status' => 'in_progress',
        ]);

        $workOrder->update(['notes' => 'still going']);
        $this->assertSame(0, WebhookDelivery::where('event', 'work_order.completed')->count());

        $workOrder->update(['status' => 'completed', 'quantity_produced' => 2, 'completed_at' => now()]);
        $this->assertSame('WO-1', $this->assertDelivered('work_order.completed')['work_order']['work_order_number']);
    }

    public function test_stock_audit_completed_event(): void
    {
        $this->subscribe('stock_audit.completed');

        $audits = app(StockAuditService::class);
        $audit = $audits->create($this->org->id, $this->admin, [
            'name' => 'Spot', 'audit_type' => 'spot', 'product_ids' => [$this->product->id],
        ]);
        $audits->start($audit);
        $audits->recordCount($audit->fresh(), $audit->items()->first(), $this->admin, 98);
        $audits->complete($audit->fresh());

        $payload = $this->assertDelivered('stock_audit.completed');
        $this->assertSame($audit->audit_number, $payload['stock_audit']['audit_number']);
        $this->assertSame(1, $payload['stock_audit']['discrepancies']);
    }

    public function test_events_are_org_scoped(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'o@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        Webhook::create([
            'organization_id' => $other->id, 'name' => 'Other hook', 'url' => 'https://example.com/o',
            'events' => ['customer.created'], 'is_active' => true,
        ]);

        Customer::create(['organization_id' => $this->org->id, 'name' => 'Mine']);

        $this->assertSame(0, WebhookDelivery::where('event', 'customer.created')->count());
    }
}
