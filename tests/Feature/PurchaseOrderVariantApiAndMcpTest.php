<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreatePurchaseOrderTool;
use App\Mcp\Tools\ReceivePurchaseOrderTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The REST API and the MCP tools accept an optional product_variant_id on
 * purchase order lines. When present it must belong to the line's product
 * (and organization); when absent the line behaves as before, so existing
 * clients keep working. Receiving a variant line credits the variant.
 */
final class PurchaseOrderVariantApiAndMcpTest extends TestCase
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

        $this->shirt = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SHIRT', 'name' => 'Shirt',
            'price' => 20, 'purchase_price' => 9, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0,
            'is_active' => true, 'has_variants' => true,
        ]);
        $this->small = $this->variant($this->shirt, 'SHIRT-S', 'Small', 2);
        $this->large = $this->variant($this->shirt, 'SHIRT-L', 'Large', 0);

        $this->plain = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'MUG', 'name' => 'Mug',
            'price' => 8, 'purchase_price' => 3, 'currency' => 'USD', 'stock' => 5, 'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function variant(Product $product, string $sku, string $title, int $stock): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id, 'organization_id' => $product->organization_id,
            'sku' => $sku, 'title' => $title, 'option_values' => ['Size' => $title],
            'price' => 20, 'purchase_price' => 8, 'stock' => $stock, 'min_stock' => 0,
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

    private function draftPo(): PurchaseOrder
    {
        return PurchaseOrder::create([
            'organization_id' => $this->org->id, 'supplier_id' => $this->supplier->id,
            'created_by' => $this->admin->id, 'po_number' => 'PO-'.uniqid(), 'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(), 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'currency' => 'USD',
        ]);
    }

    private function sentPoWithSmallLine(int $qty): array
    {
        $po = $this->draftPo();
        $po->update(['status' => PurchaseOrder::STATUS_SENT]);
        $item = $po->items()->create([
            'product_id' => $this->shirt->id, 'product_variant_id' => $this->small->id,
            'product_name' => 'Shirt', 'sku' => 'SHIRT-S', 'quantity_ordered' => $qty, 'quantity_received' => 0,
            'unit_cost' => 8, 'subtotal' => 8 * $qty, 'tax' => 0, 'total' => 8 * $qty,
        ]);

        return [$po, $item];
    }

    // ==================== REST API ====================

    public function test_api_store_persists_an_optional_variant(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['product_id' => $this->shirt->id, 'product_variant_id' => $this->large->id, 'quantity' => 4, 'unit_cost' => 8],
            ['product_id' => $this->plain->id, 'quantity' => 2, 'unit_cost' => 3],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.items.0.product_variant_id', $this->large->id);

        $this->assertDatabaseHas('purchase_order_items', [
            'product_id' => $this->shirt->id, 'product_variant_id' => $this->large->id, 'sku' => 'SHIRT-L',
        ]);
        $this->assertDatabaseHas('purchase_order_items', [
            'product_id' => $this->plain->id, 'product_variant_id' => null, 'sku' => 'MUG',
        ]);
    }

    public function test_api_store_still_accepts_a_variant_product_line_without_a_variant(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['product_id' => $this->shirt->id, 'quantity' => 1, 'unit_cost' => 8],
        ]))->assertCreated();

        $this->assertDatabaseHas('purchase_order_items', [
            'product_id' => $this->shirt->id, 'product_variant_id' => null, 'sku' => 'SHIRT',
        ]);
    }

    public function test_api_store_rejects_a_variant_of_another_product(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['product_id' => $this->plain->id, 'product_variant_id' => $this->small->id, 'quantity' => 1, 'unit_cost' => 1],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.product_variant_id');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_api_store_rejects_a_variant_from_another_organization(): void
    {
        $other = Organization::create([
            'name' => 'Other', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $foreignProduct = Product::create([
            'organization_id' => $other->id, 'sku' => 'F', 'name' => 'Foreign', 'price' => 1,
            'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'has_variants' => true,
        ]);
        $foreignVariant = $this->variant($foreignProduct, 'F-1', 'One', 0);

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['product_id' => $this->shirt->id, 'product_variant_id' => $foreignVariant->id, 'quantity' => 1, 'unit_cost' => 1],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.product_variant_id');
    }

    public function test_api_update_can_set_and_switch_a_lines_variant(): void
    {
        $po = $this->draftPo();
        $item = $po->items()->create([
            'product_id' => $this->shirt->id, 'product_name' => 'Shirt', 'sku' => 'SHIRT',
            'quantity_ordered' => 2, 'quantity_received' => 0, 'unit_cost' => 8, 'subtotal' => 16, 'tax' => 0, 'total' => 16,
        ]);

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/purchase-orders/{$po->id}", [
            'items' => [
                ['id' => $item->id, 'product_id' => $this->shirt->id, 'product_variant_id' => $this->small->id, 'quantity' => 3, 'unit_cost' => 8],
                ['product_id' => $this->shirt->id, 'product_variant_id' => $this->large->id, 'quantity' => 1, 'unit_cost' => 8],
            ],
        ])->assertOk();

        $this->assertSame($this->small->id, $item->fresh()->product_variant_id);
        $this->assertSame('SHIRT-S', $item->fresh()->sku);
        $this->assertEqualsCanonicalizing(
            [$this->small->id, $this->large->id],
            $po->items()->pluck('product_variant_id')->all()
        );
    }

    public function test_api_update_rejects_a_variant_of_another_product(): void
    {
        $po = $this->draftPo();
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/purchase-orders/{$po->id}", [
            'items' => [
                ['product_id' => $this->plain->id, 'product_variant_id' => $this->small->id, 'quantity' => 1, 'unit_cost' => 1],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.product_variant_id');
    }

    public function test_api_receiving_a_variant_line_credits_the_variant(): void
    {
        [$po, $item] = $this->sentPoWithSmallLine(5);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [['id' => $item->id, 'quantity_to_receive' => 4]],
        ])->assertOk();

        $this->assertSame(6, (int) $this->small->fresh()->stock);
        $this->assertSame(0, (int) $this->shirt->fresh()->stock);
    }

    // ==================== MCP ====================

    public function test_mcp_create_persists_an_optional_variant(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(CreatePurchaseOrderTool::class, $this->payload([
                ['product_id' => $this->shirt->id, 'product_variant_id' => $this->small->id, 'quantity' => 3, 'unit_cost' => 8],
                ['product_id' => $this->plain->id, 'quantity' => 1, 'unit_cost' => 3],
            ]))
            ->assertOk();

        $this->assertDatabaseHas('purchase_order_items', [
            'product_id' => $this->shirt->id, 'product_variant_id' => $this->small->id, 'sku' => 'SHIRT-S',
        ]);
        $this->assertDatabaseHas('purchase_order_items', [
            'product_id' => $this->plain->id, 'product_variant_id' => null,
        ]);
    }

    public function test_mcp_create_rejects_a_variant_of_another_product(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(CreatePurchaseOrderTool::class, $this->payload([
                ['product_id' => $this->plain->id, 'product_variant_id' => $this->small->id, 'quantity' => 1, 'unit_cost' => 1],
            ]))
            ->assertHasErrors(['does not belong']);

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_mcp_receiving_a_variant_line_credits_the_variant(): void
    {
        [$po, $item] = $this->sentPoWithSmallLine(5);

        InventorosServer::actingAs($this->admin)
            ->tool(ReceivePurchaseOrderTool::class, [
                'id' => $po->id,
                'items' => [['id' => $item->id, 'quantity_to_receive' => 2]],
            ])
            ->assertOk();

        $this->assertSame(4, (int) $this->small->fresh()->stock);
        $this->assertSame(0, (int) $this->shirt->fresh()->stock);
        $this->assertSame(2, PurchaseOrderItem::find($item->id)->quantity_received);
    }
}
