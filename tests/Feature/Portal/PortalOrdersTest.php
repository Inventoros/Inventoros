<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalOrdersTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
    }

    public function test_dashboard_and_order_list_show_only_the_contacts_customer(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $mine = $this->makeCustomer($org, 'Buyer A');
        $theirs = $this->makeCustomer($org, 'Buyer B');
        $contact = $this->makeContact($mine, 'a@buyer.test');
        $product = $this->makeProduct($org, 'SKU-1');

        $this->makeOrder($mine, 'ORD-A-1', OrderStatus::DELIVERED, [['product' => $product, 'quantity' => 2]]);
        $this->makeOrder($mine, 'ORD-A-2', OrderStatus::PROCESSING);
        $this->makeOrder($theirs, 'ORD-B-1');

        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($org))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Dashboard')
                ->where('stats.orders', 2)
                ->where('stats.open_orders', 1)
                ->has('recentOrders', 2));

        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($org, 'orders'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Orders/Index')
                ->has('orders.data', 2)
                ->where('orders.data.0.order_number', 'ORD-A-2')
                ->where('orders.data.1.order_number', 'ORD-A-1'));
    }

    public function test_order_detail_shows_lines_and_totals_but_not_internal_fields(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer A');
        $contact = $this->makeContact($customer, 'a@buyer.test');
        $product = $this->makeProduct($org, 'SKU-1');
        $order = $this->makeOrder($customer, 'ORD-A-1', OrderStatus::DELIVERED, [
            ['product' => $product, 'quantity' => 3, 'unit_price' => 12.5],
        ]);

        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($org, 'orders/'.$order->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Orders/Show')
                ->where('order.order_number', 'ORD-A-1')
                ->where('order.status', 'delivered')
                ->where('order.total', '37.50')
                ->has('order.items', 1)
                ->where('order.items.0.sku', 'SKU-1')
                ->where('order.items.0.quantity', 3)
                ->where('order.items.0.returnable_quantity', 3)
                ->where('order.can_request_return', true)
                ->where('order.invoice_available', true)
                ->missing('order.notes')
                ->missing('order.created_by')
                ->missing('order.metadata'));
    }

    public function test_another_customers_order_is_not_found(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $contact = $this->makeContact($this->makeCustomer($org, 'Buyer A'), 'a@buyer.test');
        $other = $this->makeOrder($this->makeCustomer($org, 'Buyer B'), 'ORD-B-1');

        $this->actingAs($contact, 'customer');
        $this->get($this->portalUrl($org, 'orders/'.$other->id))->assertNotFound();
        $this->get($this->portalUrl($org, 'orders/'.$other->id.'/invoice'))->assertNotFound();
        $this->get($this->portalUrl($org, 'orders/999999'))->assertNotFound();
    }

    public function test_another_organizations_order_is_not_found(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $contact = $this->makeContact($this->makeCustomer($orgA, 'Buyer A'), 'a@buyer.test');
        $foreign = $this->makeOrder($this->makeCustomer($orgB, 'Buyer B'), 'ORD-B-1');

        $this->actingAs($contact, 'customer');
        $this->get($this->portalUrl($orgA, 'orders/'.$foreign->id))->assertNotFound();
        $this->get($this->portalUrl($orgA, 'orders/'.$foreign->id.'/invoice'))->assertNotFound();

        // Nor by switching the URL to the other org: the session is org A's.
        $this->get($this->portalUrl($orgB, 'orders/'.$foreign->id))->assertRedirect($this->portalUrl($orgB, 'login'));
    }

    public function test_contact_downloads_their_invoice_pdf(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer A');
        $contact = $this->makeContact($customer, 'a@buyer.test');
        $product = $this->makeProduct($org, 'SKU-1');
        $order = $this->makeOrder($customer, 'ORD-A-1', OrderStatus::SHIPPED, [['product' => $product, 'quantity' => 1]]);

        $response = $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($org, 'orders/'.$order->id.'/invoice'));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('INV-000001.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertSame('INV-000001', $order->fresh()->invoice_number);
    }

    public function test_invoice_is_not_available_for_pending_or_cancelled_orders(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer A');
        $contact = $this->makeContact($customer, 'a@buyer.test');
        $pending = $this->makeOrder($customer, 'ORD-A-1', OrderStatus::PENDING);
        $cancelled = $this->makeOrder($customer, 'ORD-A-2', OrderStatus::CANCELLED);

        $this->actingAs($contact, 'customer');
        $this->get($this->portalUrl($org, 'orders/'.$pending->id.'/invoice'))->assertNotFound();
        $this->get($this->portalUrl($org, 'orders/'.$cancelled->id.'/invoice'))->assertNotFound();
        $this->assertNull($pending->fresh()->invoice_number);
    }
}
