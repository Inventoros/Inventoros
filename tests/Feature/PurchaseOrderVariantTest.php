<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Purchase order lines can name a product variant, and receiving such a line
 * credits the variant's own stock rather than the parent product's.
 */
final class PurchaseOrderVariantTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Supplier $supplier;

    private Product $shirt;

    private ProductVariant $small;

    private ProductVariant $large;

    private Product $plain;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Buyer', 'email' => 'buyer@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@buyer.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->supplier = Supplier::create([
            'organization_id' => $this->org->id, 'name' => 'Mill', 'email' => 'mill@test.com', 'is_active' => true,
        ]);
        $location = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN',
        ]);

        $this->shirt = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SHIRT', 'name' => 'Shirt',
            'price' => 20, 'purchase_price' => 9, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0,
            'is_active' => true, 'has_variants' => true, 'location_id' => $location->id,
        ]);
        $this->small = $this->variant($this->shirt, 'SHIRT-S', 'Small', 2, 7.5);
        $this->large = $this->variant($this->shirt, 'SHIRT-L', 'Large', 0, 8.5);

        $this->plain = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'MUG', 'name' => 'Mug',
            'price' => 8, 'purchase_price' => 3, 'currency' => 'USD', 'stock' => 5, 'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function variant(Product $product, string $sku, string $title, int $stock, float $cost): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id, 'organization_id' => $product->organization_id,
            'sku' => $sku, 'title' => $title, 'option_values' => ['Size' => $title],
            'price' => 20, 'purchase_price' => $cost, 'stock' => $stock, 'min_stock' => 0,
            'is_active' => true, 'position' => 0,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function payload(array $items): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'currency' => 'USD',
            'items' => $items,
        ];
    }

    private function sentPoWithVariantLine(int $qty): array
    {
        $po = PurchaseOrder::create([
            'organization_id' => $this->org->id, 'supplier_id' => $this->supplier->id,
            'created_by' => $this->admin->id, 'po_number' => 'PO-VAR-1', 'status' => PurchaseOrder::STATUS_SENT,
            'order_date' => now()->toDateString(), 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'currency' => 'USD',
        ]);
        $item = $po->items()->create([
            'product_id' => $this->shirt->id, 'product_variant_id' => $this->small->id,
            'product_name' => 'Shirt', 'sku' => 'SHIRT-S', 'quantity_ordered' => $qty, 'quantity_received' => 0,
            'unit_cost' => 7.5, 'subtotal' => 7.5 * $qty, 'tax' => 0, 'total' => 7.5 * $qty,
        ]);

        return [$po, $item];
    }

    public function test_the_receive_page_of_a_draft_po_redirects_instead_of_failing(): void
    {
        $po = PurchaseOrder::create([
            'organization_id' => $this->org->id, 'supplier_id' => $this->supplier->id,
            'created_by' => $this->admin->id, 'po_number' => 'PO-DRAFT-1', 'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(), 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'currency' => 'USD',
        ]);

        $this->actingAs($this->admin)
            ->get(route('purchase-orders.receive', $po))
            ->assertRedirect(route('purchase-orders.show', $po))
            ->assertSessionHas('error');
        // Same for editing a PO that can no longer be edited.
        $po->forceFill(['status' => PurchaseOrder::STATUS_SENT])->save();
        $this->actingAs($this->admin)
            ->get(route('purchase-orders.edit', $po))
            ->assertRedirect(route('purchase-orders.show', $po))
            ->assertSessionHas('error');
    }

    public function test_create_form_exposes_product_variants(): void
    {
        $this->actingAs($this->admin)
            ->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PurchaseOrders/Create')
                ->where('products', function ($products) {
                    $shirt = collect($products)->firstWhere('sku', 'SHIRT');

                    return $shirt['has_variants'] === true
                        && collect($shirt['variants'])->pluck('sku')->sort()->values()->all() === ['SHIRT-L', 'SHIRT-S'];
                })
            );
    }

    public function test_create_form_exposes_each_linked_supplier_cost_and_sku(): void
    {
        $this->plain->suppliers()->attach($this->supplier->id, ['cost_price' => 2.4, 'supplier_sku' => 'MILL-MUG']);

        $this->actingAs($this->admin)
            ->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products', function ($products) {
                    $mug = collect($products)->firstWhere('sku', 'MUG');
                    $link = collect($mug['supplier_costs'])->firstWhere('supplier_id', $this->supplier->id);

                    return $link !== null
                        && (float) $link['cost_price'] === 2.4
                        && $link['supplier_sku'] === 'MILL-MUG'
                        && collect($products)->firstWhere('sku', 'SHIRT')['supplier_costs'] === [];
                })
            );
    }

    public function test_store_persists_the_variant_on_the_line(): void
    {
        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload([
                ['product_id' => $this->shirt->id, 'product_variant_id' => $this->large->id, 'quantity' => 6, 'unit_cost' => 8.5],
                ['product_id' => $this->plain->id, 'quantity' => 2, 'unit_cost' => 3],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('purchase_order_items', [
            'product_id' => $this->shirt->id,
            'product_variant_id' => $this->large->id,
            'sku' => 'SHIRT-L',
            'quantity_ordered' => 6,
        ]);
        $this->assertDatabaseHas('purchase_order_items', [
            'product_id' => $this->plain->id,
            'product_variant_id' => null,
            'sku' => 'MUG',
        ]);
    }

    public function test_store_rejects_a_variant_of_another_product_and_a_missing_variant(): void
    {
        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload([
                ['product_id' => $this->plain->id, 'product_variant_id' => $this->small->id, 'quantity' => 1, 'unit_cost' => 1],
                ['product_id' => $this->shirt->id, 'quantity' => 1, 'unit_cost' => 1],
            ]))
            ->assertSessionHasErrors(['items.0.product_variant_id', 'items.1.product_variant_id']);

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_store_rejects_a_variant_from_another_organization(): void
    {
        $other = Organization::create([
            'name' => 'Other', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $foreignProduct = Product::create([
            'organization_id' => $other->id, 'sku' => 'F', 'name' => 'Foreign', 'price' => 1,
            'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'has_variants' => true,
        ]);
        $foreignVariant = $this->variant($foreignProduct, 'F-1', 'One', 0, 1);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload([
                ['product_id' => $this->shirt->id, 'product_variant_id' => $foreignVariant->id, 'quantity' => 1, 'unit_cost' => 1],
            ]))
            ->assertSessionHasErrors('items.0.product_variant_id');
    }

    public function test_update_can_switch_a_lines_variant(): void
    {
        $po = PurchaseOrder::create([
            'organization_id' => $this->org->id, 'supplier_id' => $this->supplier->id,
            'created_by' => $this->admin->id, 'po_number' => 'PO-VAR-2', 'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(), 'subtotal' => 15, 'tax' => 0, 'total' => 15, 'currency' => 'USD',
        ]);
        $item = $po->items()->create([
            'product_id' => $this->shirt->id, 'product_variant_id' => $this->small->id,
            'product_name' => 'Shirt', 'sku' => 'SHIRT-S', 'quantity_ordered' => 2, 'quantity_received' => 0,
            'unit_cost' => 7.5, 'subtotal' => 15, 'tax' => 0, 'total' => 15,
        ]);

        $this->actingAs($this->admin)
            ->put(route('purchase-orders.update', $po), $this->payload([
                ['id' => $item->id, 'product_id' => $this->shirt->id, 'product_variant_id' => $this->large->id, 'quantity' => 3, 'unit_cost' => 8.5],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame($this->large->id, $fresh->product_variant_id);
        $this->assertSame('SHIRT-L', $fresh->sku);
        $this->assertSame(3, $fresh->quantity_ordered);
    }

    public function test_receiving_a_variant_line_credits_the_variant_not_the_parent(): void
    {
        [$po, $item] = $this->sentPoWithVariantLine(5);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.process-receiving', $po), [
                'items' => [['id' => $item->id, 'quantity_to_receive' => 3]],
            ])
            ->assertRedirect(route('purchase-orders.show', $po));

        $this->assertSame(5, (int) $this->small->fresh()->stock); // 2 + 3
        $this->assertSame(0, (int) $this->shirt->fresh()->stock);
        $this->assertSame(0, ProductLocationStock::where('product_id', $this->shirt->id)->count());
        $this->assertSame(3, $item->fresh()->quantity_received);
        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $po->fresh()->status);

        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->shirt->id,
            'product_variant_id' => $this->small->id,
            'type' => 'purchase',
            'adjustment_quantity' => 3,
            'quantity_before' => 2,
            'quantity_after' => 5,
            'reference_type' => PurchaseOrder::class,
            'reference_id' => $po->id,
        ]);
    }

    public function test_receiving_the_rest_completes_the_po(): void
    {
        [$po, $item] = $this->sentPoWithVariantLine(4);
        $this->actingAs($this->admin);

        $item->receive(1);
        PurchaseOrderItem::find($item->id)->receive(10); // capped at the 3 remaining

        $this->assertSame(6, (int) $this->small->fresh()->stock);
        $this->assertSame(4, $item->fresh()->quantity_received);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $po->fresh()->status);
    }

    public function test_receiving_skips_a_line_whose_variant_was_deleted(): void
    {
        [, $item] = $this->sentPoWithVariantLine(4);
        $this->actingAs($this->admin);
        $this->small->delete();

        $this->assertNull(PurchaseOrderItem::find($item->id)->receive(2));
        $this->assertSame(0, $item->fresh()->quantity_received);
        $this->assertSame(0, (int) $this->shirt->fresh()->stock);
    }
}
