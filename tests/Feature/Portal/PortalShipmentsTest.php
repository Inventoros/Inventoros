<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Enums\OrderStatus;
use App\Models\Order\Order;
use App\Models\Shipping\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The portal order page shows the order's shipments (carrier, tracking,
 * status, dates, packed lines) and nothing internal: no label files or
 * URLs, costs, carrier ids, rates or raw carrier responses.
 */
class PortalShipmentsTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
    }

    private function ship(Order $order, array $attributes = [], ?int $quantity = null): Shipment
    {
        $shipment = Shipment::withoutGlobalScopes()->create(array_merge([
            'organization_id' => $order->organization_id,
            'order_id' => $order->id,
            'carrier' => 'easypost',
            'carrier_name' => 'UPS',
            'service' => 'Ground',
            'tracking_number' => '1Z999AA10123456784',
            'tracking_url' => 'https://www.ups.com/track?tracknum=1Z999AA10123456784',
            'status' => 'in_transit',
            'tracking_status_detail' => 'Raw carrier detail text',
            'label_path' => 'labels/secret.pdf',
            'label_url' => 'https://easypost-files.example/label-secret.pdf',
            'carrier_shipment_id' => 'shp_secret',
            'carrier_tracker_id' => 'trk_secret',
            'carrier_rates' => [['rate' => '9.99']],
            'carrier_response' => ['secret' => 'payload'],
            'cost' => 12.34,
            'currency' => 'USD',
            'to_address' => ['name' => 'Buyer'],
            'from_address' => ['name' => 'Warehouse'],
            'shipped_at' => now()->subDays(2),
        ], $attributes));

        $item = $order->items()->first();
        if ($item) {
            $shipment->items()->create(['order_item_id' => $item->id, 'quantity' => $quantity ?? $item->quantity]);
        }

        return $shipment;
    }

    public function test_order_page_lists_shipments_with_tracking_and_packed_lines(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer A');
        $contact = $this->makeContact($customer, 'a@buyer.test');
        $order = $this->makeOrder($customer, 'ORD-A-1', OrderStatus::SHIPPED, [
            ['product' => $this->makeProduct($org, 'SKU-1'), 'quantity' => 4],
        ]);
        $this->ship($order, [], 3);
        $this->ship($order, ['status' => 'cancelled', 'tracking_number' => 'CANCELLED-1']);

        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($org, 'orders/'.$order->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('order.shipments', 1)
                ->where('order.shipments.0.carrier_name', 'UPS')
                ->where('order.shipments.0.service', 'Ground')
                ->where('order.shipments.0.tracking_number', '1Z999AA10123456784')
                ->where('order.shipments.0.tracking_url', 'https://www.ups.com/track?tracknum=1Z999AA10123456784')
                ->where('order.shipments.0.status', 'in_transit')
                ->where('order.shipments.0.status_label', 'In transit')
                ->where('order.shipments.0.shipped_at', fn ($v) => $v !== null)
                ->where('order.shipments.0.delivered_at', null)
                ->has('order.shipments.0.items', 1)
                ->where('order.shipments.0.items.0.sku', 'SKU-1')
                ->where('order.shipments.0.items.0.quantity', 3)
                ->missing('order.shipments.0.label_url')
                ->missing('order.shipments.0.label_path')
                ->missing('order.shipments.0.has_label')
                ->missing('order.shipments.0.cost')
                ->missing('order.shipments.0.carrier_response')
                ->missing('order.shipments.0.carrier_rates')
                ->missing('order.shipments.0.carrier_shipment_id')
                ->missing('order.shipments.0.carrier_tracker_id')
                ->missing('order.shipments.0.tracking_status_detail')
                ->missing('order.shipments.0.from_address')
                ->missing('order.shipments.0.warehouse'));

        // Belt and braces: none of the internal values appear anywhere in the page.
        $html = $this->get($this->portalUrl($org, 'orders/'.$order->id))->getContent();
        foreach (['label-secret', 'labels/secret.pdf', 'shp_secret', 'trk_secret', '12.34', 'Raw carrier detail text', '"secret"'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "Leaked {$needle}");
        }
    }

    public function test_only_web_tracking_links_are_passed_through(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer A');
        $contact = $this->makeContact($customer, 'a@buyer.test');
        $order = $this->makeOrder($customer, 'ORD-A-1', OrderStatus::SHIPPED);
        $this->ship($order, ['tracking_url' => 'javascript:alert(1)']);

        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($org, 'orders/'.$order->id))
            ->assertInertia(fn ($page) => $page->where('order.shipments.0.tracking_url', null));
    }

    public function test_shipments_of_other_customers_and_orgs_never_appear(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $customer = $this->makeCustomer($orgA, 'Buyer A');
        $contact = $this->makeContact($customer, 'a@buyer.test');
        $mine = $this->makeOrder($customer, 'ORD-A-1', OrderStatus::SHIPPED);
        $sibling = $this->makeOrder($this->makeCustomer($orgA, 'Buyer B'), 'ORD-B-1', OrderStatus::SHIPPED);
        $foreign = $this->makeOrder($this->makeCustomer($orgB, 'Buyer C'), 'ORD-C-1', OrderStatus::SHIPPED);
        $this->ship($sibling, ['tracking_number' => 'SIBLING-TRACK']);
        $this->ship($foreign, ['tracking_number' => 'FOREIGN-TRACK']);

        // A shipment row that (wrongly) points at my order from another
        // organization must not be shown either.
        Shipment::withoutGlobalScopes()->create([
            'organization_id' => $orgB->id, 'order_id' => $mine->id, 'carrier' => 'manual',
            'tracking_number' => 'CROSS-ORG-TRACK', 'status' => 'shipped',
        ]);

        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($orgA, 'orders/'.$mine->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('order.shipments', 0));

        $this->get($this->portalUrl($orgA, 'orders/'.$sibling->id))->assertNotFound();
        $this->get($this->portalUrl($orgA, 'orders/'.$foreign->id))->assertNotFound();
    }
}
