<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Enums\OrderStatus;
use App\Models\Inventory\ProductVariant;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShipmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An order line sold as a variant names the variant ("Large") wherever the
 * line is shown: the staff order page, its shipments, and the portal.
 */
class OrderLineVariantNamesTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    public function test_order_lines_name_their_variant_for_staff_and_customers(): void
    {
        $this->markInstalled();
        $org = $this->makeOrganization('Variant Co');
        $customer = $this->makeCustomer($org, 'Buyer V');
        $contact = $this->makeContact($customer, 'v@buyer.test');
        $admin = $this->makeStaff($org, 'admin@variant.test');
        $tee = $this->makeProduct($org, 'TEE');
        $large = ProductVariant::create([
            'product_id' => $tee->id, 'organization_id' => $org->id, 'sku' => 'TEE-L', 'title' => 'Large',
            'option_values' => ['Size' => 'Large'], 'price' => 20, 'stock' => 5, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
        ]);

        $order = $this->makeOrder($customer, 'ORD-V-1', OrderStatus::DELIVERED, [['product' => $tee, 'quantity' => 1]]);
        $line = $order->items()->firstOrFail();
        $line->forceFill(['product_variant_id' => $large->id, 'sku' => 'TEE-L'])->save();

        $shipment = Shipment::create([
            'organization_id' => $org->id, 'order_id' => $order->id, 'carrier' => 'manual',
            'carrier_name' => 'UPS', 'service' => 'Ground', 'status' => 'delivered',
        ]);
        ShipmentItem::create(['shipment_id' => $shipment->id, 'order_item_id' => $line->id, 'quantity' => 1]);

        $this->actingAs($admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order.items.0.variant_title', 'Large')
                ->where('shipments.0.items.0.variant_title', 'Large'));

        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($org, 'orders/'.$order->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order.items.0.variant_title', 'Large')
                ->where('order.shipments.0.items.0.variant_title', 'Large'));
    }
}
