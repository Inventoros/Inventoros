<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\ShippingException;
use App\Imports\OrdersImport;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\ReturnOrder;
use App\Models\Order\ReturnOrderItem;
use App\Models\Shipping\Shipment;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderService;
use App\Services\ReturnOrderService;
use App\Services\Shipping\ShipmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Concerns\AssertsStockInvariants;
use Tests\TestCase;

/**
 * Every path that gives an order's stock back (cancel, reject, delete, a line
 * edit, a return receipt) must give back only what the order actually took
 * and has not already given back. Each test reproduces a pre-release audit
 * probe that restocked twice or created stock from nothing, and checks the
 * total, the location bins and the ledger afterwards.
 */
final class OrderLifecycleRestockTest extends TestCase
{
    use AssertsStockInvariants, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private ProductLocation $primary;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Lifecycle Org', 'email' => 'life@org.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@life.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN',
            'address_line_1' => '1 Dock St', 'city' => 'Portland', 'province' => 'OR',
            'postal_code' => '97201', 'country' => 'US', 'phone' => '5035550100',
            'is_default' => true, 'is_active' => true,
        ]);
        $this->primary = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'Primary', 'code' => 'PRI', 'is_active' => true,
        ]);

        $this->actingAs($this->admin);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function product(int $stock = 10, string $sku = 'WID-1'): Product
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => $sku, 'name' => "Widget {$sku}",
            'price' => 10, 'currency' => 'USD', 'stock' => $stock, 'min_stock' => 0,
            'location_id' => $this->primary->id, 'is_active' => true,
        ]);

        // Binned from the start, so every scenario can check stock == SUM(bins).
        ProductLocationStock::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id,
            'location_id' => $this->primary->id, 'quantity' => $stock,
        ]);

        return $product;
    }

    private function order(Product $product, int $qty, string $status = 'pending', bool $adjustStock = true, ?string $approval = null): Order
    {
        return $this->orders()->create(array_filter([
            'customer_name' => 'Acme', 'status' => $status, 'order_date' => now()->toDateString(),
            'approval_status' => $approval,
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 10.00]],
        ], fn ($value) => $value !== null), $this->admin, 'manual', $adjustStock)->load('items');
    }

    /**
     * Put an order back to "pending approval" the way rows written before
     * approval became a setting could be, bypassing the model guard that
     * now keeps pending orders from shipping.
     */
    private function markPendingApproval(Order $order): Order
    {
        DB::table('orders')->where('id', $order->id)->update(['approval_status' => 'pending']);

        return $order->fresh();
    }

    private function orders(): OrderService
    {
        return app(OrderService::class);
    }

    private function returns(): ReturnOrderService
    {
        return app(ReturnOrderService::class);
    }

    private function shipments(): ShipmentService
    {
        return app(ShipmentService::class);
    }

    /**
     * @param  array<int, array{0: OrderItem, 1: int}>|null  $lines
     */
    private function raiseReturn(Order $order, int $qty, ?array $lines = null): ReturnOrder
    {
        $lines ??= [[$order->items->first(), $qty]];

        return $this->returns()->create($this->org->id, $this->admin, [
            'order_id' => $order->id,
            'type' => 'return',
            'reason' => 'Unwanted',
            'items' => array_map(fn (array $l) => [
                'order_item_id' => $l[0]->id, 'quantity' => $l[1], 'condition' => 'new', 'restock' => true,
            ], $lines),
        ]);
    }

    private function receiveReturn(Order $order, int $qty): ReturnOrder
    {
        $return = $this->raiseReturn($order, $qty);
        $this->returns()->approve($return, $this->admin);

        return $this->returns()->receive($return->fresh(), $this->admin);
    }

    private function binQty(Product $product, ?ProductLocation $location = null): int
    {
        return (int) ProductLocationStock::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('location_id', ($location ?? $this->primary)->id)
            ->value('quantity');
    }

    private function assertStock(Product $product, int $expected, int $starting): void
    {
        $this->assertSame($expected, (int) $product->fresh()->stock);
        $this->assertBinnedStockBalanced();
        $this->assertLedgerExplainsStock($product, $starting);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function webEdit(Order $order, array $overrides = [], ?array $items = null): \Illuminate\Testing\TestResponse
    {
        $order = $order->fresh(['items']);

        return $this->put(route('orders.update', $order), array_merge([
            'customer_name' => $order->customer_name,
            'status' => $order->status->value,
            'order_date' => $order->order_date->toDateString(),
            'notes' => 'Just a note',
            'items' => $items ?? $order->items->map(fn (OrderItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
            ])->all(),
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // 1. Historical imports never took stock, so nothing goes back.
    // ------------------------------------------------------------------

    public function test_a_historical_import_records_that_it_committed_no_stock(): void
    {
        $product = $this->product(10);
        $csv = "external_reference,order_date,status,customer_name,customer_email,product_sku,variant_sku,quantity,unit_price,order_tax,order_shipping,notes\n"
            ."H-1,2024-01-05,pending,Acme,,WID-1,,3,10,,,\n";

        Excel::import(new OrdersImport($this->admin, true), UploadedFile::fake()->createWithContent('orders.csv', $csv));

        $order = Order::where('external_reference', 'H-1')->firstOrFail();
        $this->assertFalse($order->stock_committed);
        $this->assertStock($product, 10, 10);

        $this->orders()->cancel($order);

        $this->assertStock($product, 10, 10);
        $this->assertSame(0, $this->ledgerRows($product, 'order_cancellation'));
    }

    public function test_an_ordinary_order_records_that_it_committed_stock(): void
    {
        $order = $this->order($this->product(10), 3);

        $this->assertTrue($order->fresh()->stock_committed);
    }

    public function test_cancelling_a_historical_order_does_not_restock(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 3, adjustStock: false);

        $this->orders()->cancel($order);

        $this->assertStock($product, 10, 10);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_rejecting_a_historical_order_does_not_restock(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 3, adjustStock: false, approval: 'pending');

        $this->orders()->reject($order, $this->admin, 'No');

        $this->assertStock($product, 10, 10);
    }

    public function test_deleting_a_historical_order_does_not_restock(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 3, adjustStock: false);

        $this->delete(route('orders.destroy', $order))->assertSessionHasNoErrors();

        $this->assertStock($product, 10, 10);
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_editing_a_historical_orders_lines_moves_no_stock(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 3, adjustStock: false);

        DB::transaction(fn () => $this->orders()->replaceItems($order->fresh(), [
            ['product_id' => $product->id, 'quantity' => 7, 'unit_price' => 10.00],
        ]));

        $this->assertSame(7, (int) $order->fresh()->items()->sum('quantity'));
        $this->assertStock($product, 10, 10);
    }

    // ------------------------------------------------------------------
    // 2. Cancel, then reject, must not restock twice.
    // ------------------------------------------------------------------

    public function test_rejecting_an_order_that_was_already_cancelled_does_not_restock_again(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 4, approval: 'pending');
        $this->assertTrue($order->isPendingApproval());

        $this->orders()->cancel($order);
        $this->assertStock($product, 10, 10);

        try {
            $this->orders()->reject($order->fresh(), $this->admin, 'Too late');
            $this->fail('Rejecting a cancelled order should be refused.');
        } catch (InvalidStateException) {
        }

        $this->assertStock($product, 10, 10);
        $this->assertSame(1, $this->ledgerRows($product, 'order_cancellation'));
        $this->assertFalse($order->fresh()->isPendingApproval());
    }

    // ------------------------------------------------------------------
    // 3 + 4. Returns only against goods that left; restocks net of returns.
    // ------------------------------------------------------------------

    public function test_a_return_cannot_be_raised_against_an_order_that_has_not_shipped(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 5);

        try {
            $this->raiseReturn($order, 5);
            $this->fail('A return against an unshipped order should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order_id', $e->errors());
        }

        $this->orders()->cancel($order);

        $this->assertStock($product, 10, 10);
        $this->assertSame(0, ReturnOrder::count());
    }

    public function test_a_return_cannot_be_raised_against_a_cancelled_order(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 5);
        $this->orders()->cancel($order);

        try {
            $this->raiseReturn($order->fresh('items'), 5);
            $this->fail('A return against a cancelled order should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order_id', $e->errors());
        }

        $this->assertStock($product, 10, 10);
    }

    public function test_a_return_can_be_raised_once_goods_have_left_in_a_shipment(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 5);
        $shipment = $this->shipments()->create($order, ['carrier' => 'manual', 'tracking_number' => 'T1'], $this->admin);
        $this->shipments()->markShipped($shipment, $this->admin);

        $this->receiveReturn($order->fresh('items'), 2);

        $this->assertStock($product, 7, 10);
    }

    public function test_cancelling_after_a_received_return_restocks_only_what_was_not_returned(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 5, 'delivered');
        $this->receiveReturn($order, 3);
        $this->assertStock($product, 8, 10);

        // Status moved back by hand (no shipments recorded), then cancelled:
        // only the 2 units still out may come back.
        $this->orders()->transitionStatus($order->fresh(), OrderStatus::PENDING);
        $this->orders()->cancel($order->fresh());

        $this->assertStock($product, 10, 10);
    }

    public function test_cancelling_after_a_full_return_restocks_nothing(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 5, 'delivered');
        $this->receiveReturn($order, 5);
        $this->orders()->transitionStatus($order->fresh(), OrderStatus::PENDING);

        $this->orders()->cancel($order->fresh());

        $this->assertStock($product, 10, 10);
    }

    public function test_an_order_with_an_open_return_cannot_be_cancelled(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 5, 'delivered');
        $this->raiseReturn($order, 5);
        $this->orders()->transitionStatus($order->fresh(), OrderStatus::PENDING);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('open return');

        try {
            $this->orders()->cancel($order->fresh());
        } finally {
            $this->assertStock($product, 5, 10);
        }
    }

    public function test_a_return_on_a_cancelled_order_cannot_be_received(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 5, 'delivered');
        $return = $this->raiseReturn($order, 5);
        $this->returns()->approve($return, $this->admin);
        // Legacy data: the order was cancelled while the return was open.
        Order::whereKey($order->id)->update(['status' => 'cancelled']);

        try {
            $this->returns()->receive($return->fresh(), $this->admin);
            $this->fail('Receiving a return against a cancelled order should be refused.');
        } catch (InvalidStateException) {
        }

        $this->assertStock($product, 5, 10);
    }

    // ------------------------------------------------------------------
    // 5 + 11. A header-only edit leaves lines, returns and bins alone.
    // ------------------------------------------------------------------

    public function test_a_notes_only_edit_keeps_the_order_lines_and_their_return(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 5, 'delivered');
        $lineId = $order->items->first()->id;
        $this->receiveReturn($order, 5);
        $this->assertStock($product, 10, 10);

        $this->webEdit($order)->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame('Just a note', $order->fresh()->notes);
        $this->assertSame([$lineId], $order->fresh()->items()->pluck('id')->all());
        $this->assertSame(1, ReturnOrderItem::count());

        // The return still counts: nothing more can come back.
        try {
            $this->raiseReturn($order->fresh('items'), 5);
            $this->fail('A second full return should be refused.');
        } catch (ValidationException) {
        }

        $this->assertStock($product, 10, 10);
    }

    public function test_line_edits_are_refused_on_an_order_with_returns(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 5, 'delivered');
        $this->receiveReturn($order, 2);

        $this->webEdit($order, [], [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 10.00]])
            ->assertSessionHas('error');

        $this->assertSame(5, (int) $order->fresh()->items()->sum('quantity'));
        $this->assertStock($product, 7, 10);
    }

    public function test_an_order_line_with_return_lines_cannot_be_deleted_from_under_them(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 5, 'delivered');
        $this->raiseReturn($order, 2);

        $this->expectException(QueryException::class);

        OrderItem::whereKey($order->items->first()->id)->delete();
    }

    public function test_an_order_with_returns_cannot_be_deleted(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 5, 'delivered');
        $this->receiveReturn($order, 2);

        $this->delete(route('orders.destroy', $order))->assertSessionHas('error');

        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
        $this->assertStock($product, 7, 10);
    }

    public function test_a_header_only_edit_does_not_move_stock_between_locations(): void
    {
        $product = $this->product(10);
        // A second, higher-priority warehouse location holding 10 more.
        $preferredWarehouse = Warehouse::create([
            'organization_id' => $this->org->id, 'name' => 'Preferred', 'code' => 'PREF',
            'address_line_1' => '2 Dock St', 'city' => 'Portland', 'province' => 'OR',
            'postal_code' => '97201', 'country' => 'US', 'phone' => '5035550101',
            'is_active' => true, 'priority' => 10,
        ]);
        $preferred = ProductLocation::create([
            'organization_id' => $this->org->id, 'warehouse_id' => $preferredWarehouse->id,
            'name' => 'Preferred', 'code' => 'PREF', 'is_active' => true,
        ]);
        ProductLocationStock::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id,
            'location_id' => $preferred->id, 'quantity' => 10,
        ]);
        Product::withoutGlobalScopes()->whereKey($product->id)->update(['stock' => 20]);

        $order = $this->order($product, 3); // drawn from the preferred location
        $this->assertSame(10, $this->binQty($product));
        $this->assertSame(7, $this->binQty($product, $preferred));
        $ledgerRows = $this->ledgerRows($product, 'order_fulfillment') + $this->ledgerRows($product, 'order_cancellation');

        $this->webEdit($order)->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame(10, $this->binQty($product));
        $this->assertSame(7, $this->binQty($product, $preferred));
        $this->assertSame(17, (int) $product->fresh()->stock);
        $this->assertBinnedStockBalanced();
        $this->assertSame($ledgerRows, $this->ledgerRows($product, 'order_fulfillment') + $this->ledgerRows($product, 'order_cancellation'));
    }

    // ------------------------------------------------------------------
    // 6. A cancelled order's lines are frozen.
    // ------------------------------------------------------------------

    public function test_editing_the_lines_of_a_cancelled_order_is_refused(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 2);
        $this->orders()->cancel($order);
        $this->assertStock($product, 10, 10);

        $this->webEdit($order, ['status' => 'cancelled'], [
            ['id' => $order->items->first()->id, 'product_id' => $product->id, 'quantity' => 7, 'unit_price' => 10.00],
        ])->assertSessionHas('error');

        $this->assertSame(2, (int) $order->fresh()->items()->sum('quantity'));
        $this->assertStock($product, 10, 10);
    }

    public function test_a_notes_edit_on_a_cancelled_order_is_allowed_and_moves_no_stock(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 2);
        $this->orders()->cancel($order);

        $this->webEdit($order, ['status' => 'cancelled'])->assertSessionMissing('error');

        $this->assertSame('Just a note', $order->fresh()->notes);
        $this->assertStock($product, 10, 10);
    }

    public function test_cancelling_through_the_web_edit_uses_the_shared_cancel(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 3);
        $shipment = $this->shipments()->create($order, ['carrier' => 'manual'], $this->admin);

        $this->webEdit($order, ['status' => 'cancelled'])->assertSessionMissing('error');

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(ShipmentStatus::CANCELLED, $shipment->fresh()->status);
        $this->assertStock($product, 10, 10);
    }

    // ------------------------------------------------------------------
    // 7. Cancelling an order closes its open shipments.
    // ------------------------------------------------------------------

    public function test_cancelling_an_order_cancels_its_pending_shipment_so_it_cannot_ship(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 3);
        $shipment = $this->shipments()->create($order, ['carrier' => 'manual', 'tracking_number' => 'T1'], $this->admin);

        $this->orders()->cancel($order);

        $this->assertSame(ShipmentStatus::CANCELLED, $shipment->fresh()->status);

        try {
            $this->shipments()->markShipped($shipment->fresh(), $this->admin);
            $this->fail('A cancelled shipment should not ship.');
        } catch (ShippingException) {
        }

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertStock($product, 10, 10);
    }

    public function test_deleting_an_order_cancels_its_pending_shipment(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 3);
        $shipment = $this->shipments()->create($order, ['carrier' => 'manual'], $this->admin);

        $this->delete(route('orders.destroy', $order))->assertSessionMissing('error');

        $this->assertSame(ShipmentStatus::CANCELLED, $shipment->fresh()->status);
        $this->assertStock($product, 10, 10);
    }

    public function test_an_order_with_a_bought_label_cannot_be_cancelled_until_it_is_voided(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 3);
        $shipment = $this->shipments()->create($order, ['carrier' => 'manual'], $this->admin);
        $shipment->forceFill(['status' => ShipmentStatus::LABEL_CREATED, 'label_url' => 'https://labels.test/1.pdf'])->save();

        try {
            $this->orders()->cancel($order);
            $this->fail('Cancelling with a bought label should be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('label', $e->getMessage());
        }

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertSame(ShipmentStatus::LABEL_CREATED, $shipment->fresh()->status);
        $this->assertStock($product, 7, 10);
    }

    public function test_a_shipment_of_a_cancelled_order_cannot_be_marked_shipped(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 3);
        $shipment = $this->shipments()->create($order, ['carrier' => 'manual'], $this->admin);
        // Legacy data: order cancelled before cancel() closed open shipments.
        $this->orders()->cancel($order);
        Shipment::withoutGlobalScopes()->whereKey($shipment->id)->update(['status' => ShipmentStatus::PENDING->value]);

        try {
            $this->shipments()->markShipped($shipment->fresh(), $this->admin);
            $this->fail('Marking a cancelled order\'s shipment shipped should be refused.');
        } catch (ShippingException) {
        }

        // A carrier tracking update is ignored rather than reviving the order.
        $this->shipments()->applyTrackingStatus($shipment->fresh(), ShipmentStatus::IN_TRANSIT);

        $this->assertSame(ShipmentStatus::PENDING, $shipment->fresh()->status);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertStock($product, 10, 10);
    }

    public function test_cancelling_a_shipment_that_already_left_is_refused_under_lock(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 3);
        $shipment = $this->shipments()->create($order, ['carrier' => 'manual'], $this->admin);
        $stale = $shipment->fresh();
        $this->shipments()->markShipped($shipment, $this->admin);

        $this->expectException(ShippingException::class);

        $this->shipments()->cancel($stale);
    }

    // ------------------------------------------------------------------
    // 8. Reject after goods left must not restock them.
    // ------------------------------------------------------------------

    public function test_rejecting_an_order_whose_goods_have_shipped_is_refused(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 3);
        $shipment = $this->shipments()->create($order, ['carrier' => 'manual'], $this->admin);
        $this->shipments()->markShipped($shipment, $this->admin);
        $order = $this->markPendingApproval($order);
        $this->assertTrue($order->isPendingApproval());

        try {
            $this->orders()->reject($order->fresh(), $this->admin, 'No');
            $this->fail('Rejecting an order whose goods left should be refused.');
        } catch (\RuntimeException) {
        }

        $this->assertStock($product, 7, 10);
        $this->assertSame(0, $this->ledgerRows($product, 'order_cancellation'));
    }

    public function test_rejecting_an_order_cancels_its_pending_shipment(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 3);
        $shipment = $this->shipments()->create($order, ['carrier' => 'manual'], $this->admin);
        $order = $this->markPendingApproval($order);

        $this->orders()->reject($order, $this->admin, 'No');

        $this->assertSame(ShipmentStatus::CANCELLED, $shipment->fresh()->status);
        $this->assertStock($product, 10, 10);
    }

    // ------------------------------------------------------------------
    // Returns: one request cannot repeat a line past its cap.
    // ------------------------------------------------------------------

    public function test_a_return_repeating_one_line_is_capped_by_the_summed_quantity(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 2, 'delivered');
        $line = $order->items->first();

        try {
            $this->raiseReturn($order, 0, [[$line, 2], [$line, 2], [$line, 2]]);
            $this->fail('Repeating a line past its quantity should be refused.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $this->assertSame(0, ReturnOrder::count());
        $this->assertStock($product, 8, 10);
    }

    public function test_receiving_rechecks_the_returnable_quantity(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 2, 'delivered');
        $return = $this->raiseReturn($order, 2);
        // Legacy data: a second line on the same return for the same order line.
        ReturnOrderItem::create([
            'return_order_id' => $return->id, 'order_item_id' => $order->items->first()->id,
            'product_id' => $product->id, 'quantity' => 2, 'condition' => 'new', 'restock' => true,
        ]);
        $this->returns()->approve($return->fresh(), $this->admin);

        try {
            $this->returns()->receive($return->fresh(), $this->admin);
            $this->fail('Receiving more than was sold should be refused.');
        } catch (ValidationException) {
        }

        $this->assertSame('approved', $return->fresh()->status);
        $this->assertStock($product, 8, 10);
    }

    public function test_the_web_return_form_refuses_a_repeated_line(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 2, 'delivered');
        $line = $order->items->first();
        $row = ['order_item_id' => $line->id, 'product_id' => $product->id, 'quantity' => 1, 'condition' => 'new', 'restock' => true];

        $this->post(route('returns.store'), [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Unwanted',
            'items' => [$row, $row, $row],
        ])->assertSessionHasErrors('items.1.order_item_id');

        $this->assertSame(0, ReturnOrder::count());
    }

    public function test_the_api_refuses_a_return_repeating_a_line(): void
    {
        $product = $this->product(10);
        $order = $this->order($product, 2, 'delivered');
        $row = ['order_item_id' => $order->items->first()->id, 'quantity' => 1, 'condition' => 'new', 'restock' => true];
        auth()->forgetGuards();
        \Laravel\Sanctum\Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/returns', [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Unwanted',
            'items' => [$row, $row],
        ])->assertStatus(422)->assertJsonValidationErrors('items.1.order_item_id');

        $this->assertSame(0, ReturnOrder::count());
    }

    // ------------------------------------------------------------------
    // The central calculation.
    // ------------------------------------------------------------------

    public function test_the_migration_marks_every_existing_order_as_having_committed_stock(): void
    {
        $product = $this->product(20);
        $recent = $this->order($product, 2);
        // An order from before order_fulfillment ledger rows existed (v1.0.0
        // to v1.0.2): it took stock, but the ledger has no row saying so.
        $legacy = $this->order($product, 3);
        \App\Models\Inventory\StockAdjustment::withoutGlobalScopes()
            ->where('reference_type', Order::class)
            ->where('reference_id', $legacy->id)
            ->delete();

        $migration = require database_path('migrations/2026_09_28_092144_add_stock_committed_to_orders_table.php');
        $migration->down();
        $migration->up();

        $this->assertTrue((bool) $recent->fresh()->stock_committed);
        $this->assertTrue((bool) $legacy->fresh()->stock_committed);

        // So cancelling the old order still gives its 3 units back.
        $this->orders()->cancel($legacy->fresh());

        $this->assertSame(18, (int) $product->fresh()->stock);
        $this->assertSame(18, $this->binQty($product));
        $this->assertBinnedStockBalanced();
        $this->assertSame(1, $this->ledgerRows($product, 'order_cancellation'));
    }

    public function test_restockable_quantities_net_off_received_returns_and_honour_the_commit_flag(): void
    {
        $product = $this->product(20);
        $order = $this->order($product, 5, 'delivered');
        $this->receiveReturn($order, 2);
        $historical = $this->order($product, 4, 'delivered', adjustStock: false);

        $this->assertSame([$order->items->first()->id => 3], $this->orders()->restockableQuantities($order->fresh()));
        $this->assertSame([$historical->items->first()->id => 0], $this->orders()->restockableQuantities($historical->fresh()));
    }
}
