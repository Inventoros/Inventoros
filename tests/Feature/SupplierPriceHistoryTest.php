<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\SupplierPriceHistory;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Receiving a purchase order line records the unit cost the supplier charged.
 */
final class SupplierPriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'is_active' => true]);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'P-1', 'name' => 'Part',
            'price' => 10, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0,
        ]);
    }

    private function sentPurchaseOrder(float $unitCost, int $quantity = 10): PurchaseOrderItem
    {
        $po = PurchaseOrder::create([
            'organization_id' => $this->org->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => PurchaseOrder::generatePONumber($this->org->id),
            'status' => PurchaseOrder::STATUS_SENT,
            'order_date' => now(),
            'subtotal' => $unitCost * $quantity, 'tax' => 0, 'shipping' => 0, 'total' => $unitCost * $quantity,
        ]);

        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'quantity_ordered' => $quantity,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
            'subtotal' => $unitCost * $quantity, 'tax' => 0, 'total' => $unitCost * $quantity,
        ]);
    }

    public function test_receiving_a_po_line_records_its_unit_cost(): void
    {
        $item = $this->sentPurchaseOrder(4.2);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.process-receiving', $item->purchase_order_id), [
                'items' => [['id' => $item->id, 'quantity_to_receive' => 10]],
            ])->assertRedirect();

        $entry = SupplierPriceHistory::sole();
        $this->assertSame($this->org->id, $entry->organization_id);
        $this->assertSame($this->product->id, $entry->product_id);
        $this->assertSame($this->supplier->id, $entry->supplier_id);
        $this->assertSame($item->purchase_order_id, $entry->purchase_order_id);
        $this->assertSame(SupplierPriceHistory::SOURCE_PURCHASE_ORDER, $entry->source);
        $this->assertEquals(4.2, (float) $entry->cost_price);
        $this->assertSame($this->admin->id, $entry->user_id);
    }

    public function test_partial_receipts_of_one_line_record_the_cost_once(): void
    {
        $item = $this->sentPurchaseOrder(3.0);

        foreach ([4, 6] as $qty) {
            $this->actingAs($this->admin)
                ->post(route('purchase-orders.process-receiving', $item->purchase_order_id), [
                    'items' => [['id' => $item->id, 'quantity_to_receive' => $qty]],
                ]);
        }

        $this->assertSame(10, $item->fresh()->quantity_received);
        $this->assertSame(1, SupplierPriceHistory::count());
    }

    public function test_the_model_receive_method_records_for_every_surface(): void
    {
        // API, MCP and web all funnel through PurchaseOrderItem::receive().
        $item = $this->sentPurchaseOrder(7.75);
        $this->actingAs($this->admin);

        $item->receive(5);

        $this->assertEquals(7.75, (float) SupplierPriceHistory::sole()->cost_price);
    }

    public function test_receiving_a_variant_line_records_its_unit_cost_against_the_variant(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id, 'organization_id' => $this->org->id,
            'sku' => 'P-1-L', 'title' => 'Large', 'option_values' => ['Size' => 'Large'],
            'price' => 20, 'purchase_price' => 5, 'stock' => 0, 'min_stock' => 0,
            'is_active' => true, 'position' => 0,
        ]);
        $item = $this->sentPurchaseOrder(6.4, 4);
        $item->forceFill(['product_variant_id' => $variant->id, 'sku' => 'P-1-L'])->save();
        $this->actingAs($this->admin);

        $item->fresh()->receive(2);
        $item->fresh()->receive(2);

        $entry = SupplierPriceHistory::sole();
        $this->assertSame($this->product->id, $entry->product_id);
        $this->assertSame($variant->id, $entry->product_variant_id);
        $this->assertSame($this->supplier->id, $entry->supplier_id);
        $this->assertEquals(6.4, (float) $entry->cost_price);
        $this->assertSame(4, (int) $variant->fresh()->stock);

        // The product page names the variant the cost was for.
        $this->get(route('products.show', $this->product))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('priceHistory.0.variant_title', 'Large')
            );
    }
}
