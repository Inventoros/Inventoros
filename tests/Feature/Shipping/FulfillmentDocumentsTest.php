<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Models\Auth\Organization;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Shipping\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Documents\FulfillmentDocumentService;
use App\Services\Shipping\OrderShippingPanel;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FulfillmentDocumentsTest extends TestCase
{
    use RefreshDatabase, ShippingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
    }

    private function documents(): FulfillmentDocumentService
    {
        return app(FulfillmentDocumentService::class);
    }

    private function parcel(Order $order, int $quantity = 1): Shipment
    {
        return app(ShipmentService::class)->create($order, [
            'carrier' => 'manual',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => $quantity]],
            'to_address' => ['name' => 'Original Recipient', 'street1' => '25 Original Street', 'city' => 'Toronto', 'state' => 'ON', 'zip' => 'M5V 1A1', 'country' => 'CA'],
        ], $this->admin);
    }

    #[DataProvider('parcelStatuses')]
    public function test_only_goods_that_left_the_warehouse_reduce_the_pick_list(string $status, int $remaining): void
    {
        $order = $this->makeOrder();
        $this->parcel($order)->forceFill(['status' => $status])->save();

        $data = $this->documents()->pickListData($order);

        $this->assertSame($remaining, $data['lines'][0]['quantity']);
        $this->assertSame(2, $data['lines'][1]['quantity']);
    }

    public static function parcelStatuses(): array
    {
        return [
            ['pending', 3], ['label_created', 3], ['cancelled', 3],
            ['shipped', 2], ['in_transit', 2], ['delivered', 2], ['exception', 2],
        ];
    }

    public function test_a_fully_shipped_line_is_omitted_instead_of_asking_for_it_again(): void
    {
        $order = $this->makeOrder();
        $parcel = $this->parcel($order, 3);
        app(ShipmentService::class)->markShipped($parcel, $this->admin);

        $data = $this->documents()->pickListData($order->fresh());
        $this->assertSame([['name' => 'Gadget', 'sku' => 'GAD-1', 'quantity' => 2]], $data['lines']);
    }

    #[DataProvider('unpickableOrders')]
    public function test_unpickable_or_historical_orders_cannot_produce_new_picking_work(array $attributes): void
    {
        $order = $this->makeOrder();
        $order->forceFill($attributes)->save();

        $this->actingAs($this->admin)->get(route('orders.pick-list', $order))->assertStatus(409);
    }

    public static function unpickableOrders(): array
    {
        return [
            [['status' => OrderStatus::CANCELLED]],
            [['status' => OrderStatus::SHIPPED]],
            [['status' => OrderStatus::DELIVERED]],
            [['approval_status' => OrderApprovalStatus::PENDING]],
            [['approval_status' => OrderApprovalStatus::REJECTED]],
            [['stock_committed' => false]],
        ];
    }

    public function test_packing_slips_use_each_parcels_exact_quantities_and_stored_address(): void
    {
        $order = $this->makeOrder();
        $first = $this->parcel($order, 1);
        $second = $this->parcel($order, 2);
        $order->forceFill(['customer_name' => 'New Name', 'customer_address' => '99 New Address'])->save();
        $this->widget->update(['name' => 'Renamed Product', 'sku' => 'NEW-SKU']);
        $this->widget->delete();

        $firstData = $this->documents()->packingSlipData($first);
        $secondData = $this->documents()->packingSlipData($second);

        $this->assertSame([['name' => 'Widget', 'sku' => 'WID-1', 'quantity' => 1]], $firstData['lines']);
        $this->assertSame(2, $secondData['lines'][0]['quantity']);
        $this->assertSame(['Original Recipient', '25 Original Street', 'Toronto ON M5V 1A1', 'CA'], $firstData['addressLines']);
        $this->assertNull($firstData['customerName']);
    }

    public function test_pick_lists_use_stored_variant_skus_and_names_after_product_changes(): void
    {
        $order = $this->makeOrder();
        $order->items->first()->update(['sku' => 'SHIRT-RED-M', 'product_name' => 'Original Shirt']);
        $this->widget->update(['name' => 'Renamed Product', 'sku' => 'RENAMED']);
        $this->widget->delete();

        $this->assertSame(['name' => 'Original Shirt', 'sku' => 'SHIRT-RED-M', 'quantity' => 3], $this->documents()->pickListData($order)['lines'][0]);
    }

    public function test_customer_documents_escape_text_and_do_not_expose_prices_notes_or_payment_data(): void
    {
        $order = $this->makeOrder([], ['notes' => 'PRIVATE-WAREHOUSE-NOTE', 'metadata' => ['private_key' => 'PRIVATE-METADATA']]);
        $order->items->first()->update(['product_name' => '<script>Customer item</script>', 'unit_price' => '12345.67', 'unit_cost' => '9876.54']);
        $shipment = $this->parcel($order);
        $shipment->forceFill(['to_address' => ['name' => '<b>Recipient</b>', 'street1' => '25 Original Street', 'email' => 'private@example.test', 'phone' => 'PRIVATE-PHONE', 'private' => 'PRIVATE-ADDRESS']])->save();

        $html = view('pdf.fulfillment', $this->documents()->packingSlipData($shipment->fresh()))->render();
        $this->assertStringContainsString('&lt;script&gt;Customer item&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;Recipient&lt;/b&gt;', $html);
        foreach (['<script>', '<b>Recipient</b>', '12345.67', '9876.54', 'PRIVATE-WAREHOUSE-NOTE', 'PRIVATE-METADATA', 'private@example.test', 'PRIVATE-PHONE', 'PRIVATE-ADDRESS'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
    }

    public function test_printing_documents_does_not_mutate_stock_invoices_orders_or_shipments(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->parcel($order);
        $orderBefore = $order->fresh()->getRawOriginal();
        $shipmentBefore = $shipment->fresh()->getRawOriginal();
        $stockBefore = [$this->widget->fresh()->stock, $this->gadget->fresh()->stock, StockAdjustment::count()];

        foreach ([route('orders.pick-list', $order), route('shipments.packing-slip', $shipment)] as $url) {
            $response = $this->actingAs($this->admin)->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString('inline;', $response->headers->get('content-disposition'));
        }

        $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
        $this->assertSame($shipmentBefore, $shipment->fresh()->getRawOriginal());
        $this->assertSame($stockBefore, [$this->widget->fresh()->stock, $this->gadget->fresh()->stock, StockAdjustment::count()]);
        $this->assertNull($order->fresh()->invoice_number);
    }

    public function test_cancelled_parcels_cannot_produce_packing_slips(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->parcel($order);
        app(ShipmentService::class)->cancel($shipment);
        $this->actingAs($this->admin)->get(route('shipments.packing-slip', $shipment))->assertStatus(409);
    }

    #[DataProvider('documentPermissions')]
    public function test_document_routes_require_the_matching_permissions(array $permissions, int $pickStatus, int $slipStatus): void
    {
        $order = $this->makeOrder();
        $shipment = $this->parcel($order);
        $user = $this->memberWith($permissions);
        $this->actingAs($user)->get(route('orders.pick-list', $order))->assertStatus($pickStatus);
        $this->actingAs($user)->get(route('shipments.packing-slip', $shipment))->assertStatus($slipStatus);
    }

    public static function documentPermissions(): array
    {
        return [
            [[], 403, 403],
            [['view_orders'], 200, 403],
            [['view_shipments'], 403, 403],
            [['view_orders', 'view_shipments'], 200, 200],
        ];
    }

    public function test_documents_require_both_order_and_shipment_warehouse_access(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->parcel($order);
        $annex = Warehouse::create(['organization_id' => $this->organization->id, 'name' => 'Annex', 'code' => 'ANX', 'is_active' => true]);
        $user = $this->memberWith(['view_orders', 'view_shipments']);
        $user->warehouses()->attach($annex->id);

        $this->actingAs($user)->get(route('orders.pick-list', $order))->assertForbidden();
        $this->actingAs($user)->get(route('shipments.packing-slip', $shipment))->assertForbidden();
        $shipment->forceFill(['warehouse_id' => $annex->id])->save();
        $this->actingAs($user)->get(route('shipments.packing-slip', $shipment))->assertForbidden();

        $user->warehouses()->attach($this->warehouse->id);
        $this->actingAs($user->fresh())->get(route('orders.pick-list', $order))->assertOk();
        $this->actingAs($user->fresh())->get(route('shipments.packing-slip', $shipment))->assertOk();
    }

    public function test_other_organizations_cannot_read_fulfillment_documents(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->parcel($order);
        $other = Organization::create(['name' => 'Other', 'email' => 'other@example.test']);
        $user = User::create(['name' => 'Other Admin', 'email' => 'otheradmin@example.test', 'password' => bcrypt('password'), 'organization_id' => $other->id, 'role' => 'admin']);

        $this->actingAs($user)->get(route('orders.pick-list', $order))->assertNotFound();
        $this->actingAs($user)->get(route('shipments.packing-slip', $shipment))->assertNotFound();
    }

    public function test_the_shipment_card_only_offers_printing_for_accessible_active_parcels(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->parcel($order);
        $user = $this->memberWith(['view_orders', 'view_shipments']);
        $annex = Warehouse::create(['organization_id' => $this->organization->id, 'name' => 'Annex', 'code' => 'ANX', 'is_active' => true]);
        $user->warehouses()->attach($annex->id);
        $panel = app(OrderShippingPanel::class);

        $this->assertFalse($panel->forOrder($order, $user)['shipments'][0]['can_print_packing_slip']);
        $user->warehouses()->attach($this->warehouse->id);
        $this->assertTrue($panel->forOrder($order, $user->fresh())['shipments'][0]['can_print_packing_slip']);
        app(ShipmentService::class)->cancel($shipment);
        $this->assertFalse($panel->forOrder($order, $user->fresh())['shipments'][0]['can_print_packing_slip']);
    }

    public function test_guests_cannot_download_fulfillment_documents(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->parcel($order);
        $this->get(route('orders.pick-list', $order))->assertRedirect(route('login'));
        $this->get(route('shipments.packing-slip', $shipment))->assertRedirect(route('login'));
    }
}
