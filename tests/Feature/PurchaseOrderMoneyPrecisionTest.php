<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreatePurchaseOrderTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase order unit costs, shipping and tax are stored to the cent, so a
 * value with more decimals is rejected on every surface rather than rounded.
 */
class PurchaseOrderMoneyPrecisionTest extends TestCase
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
        $this->org = Organization::create(['name' => 'Buyer', 'email' => 'buyer@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@buyer.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Mill', 'email' => 'mill@test.com', 'is_active' => true]);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'MUG', 'name' => 'Mug', 'price' => 8, 'purchase_price' => 3,
            'currency' => 'USD', 'stock' => 5, 'min_stock' => 0, 'is_active' => true,
        ]);
    }

    private function payload(array $overrides = [], array $item = []): array
    {
        return $overrides + [
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'currency' => 'USD',
            'items' => [$item + ['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => '3.00']],
        ];
    }

    private function draftPo(): PurchaseOrder
    {
        return PurchaseOrder::create([
            'organization_id' => $this->org->id, 'supplier_id' => $this->supplier->id, 'created_by' => $this->admin->id,
            'po_number' => 'PO-PREC-1', 'status' => PurchaseOrder::STATUS_DRAFT, 'order_date' => now()->toDateString(),
            'subtotal' => 0, 'tax' => 0, 'total' => 0, 'currency' => 'USD',
        ]);
    }

    public function test_web_rejects_sub_cent_costs_shipping_and_tax(): void
    {
        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload(['shipping' => '1.005', 'tax' => '0.125'], ['unit_cost' => '2.345']))
            ->assertSessionHasErrors(['items.0.unit_cost', 'shipping', 'tax']);
        $this->assertSame(0, PurchaseOrder::count());

        $po = $this->draftPo();
        $this->actingAs($this->admin)
            ->put(route('purchase-orders.update', $po), $this->payload(['shipping' => '1.005'], ['unit_cost' => '2.345']))
            ->assertSessionHasErrors(['items.0.unit_cost', 'shipping']);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload(['shipping' => '1.5', 'tax' => '0.25'], ['unit_cost' => '2.35']))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, PurchaseOrder::count());
    }

    public function test_rest_rejects_sub_cent_costs_shipping_and_tax(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/purchase-orders', $this->payload(['shipping' => 1.005, 'tax' => 0.125], ['unit_cost' => 2.345]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.unit_cost', 'shipping', 'tax']);

        $po = $this->draftPo();
        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['tax' => 0.001, 'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 1.001]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.unit_cost', 'tax']);
    }

    public function test_graphql_rejects_sub_cent_costs_shipping_and_tax(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $create = fn (string $extra, float $cost) => $this->postJson('/graphql', [
            'query' => 'mutation($items: [PurchaseOrderItemInput!]!) { createPurchaseOrder(supplier_id: '.$this->supplier->id.', order_date: "2026-09-01", currency: "USD"'.$extra.', items: $items) { id } }',
            'variables' => ['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => $cost]]],
        ])->json('errors');

        $this->assertNotEmpty($create('', 2.345));
        $this->assertNotEmpty($create(', shipping: 1.005', 2));
        $this->assertNotEmpty($create(', tax: 0.125', 2));
        $this->assertSame(0, PurchaseOrder::count());

        $po = $this->draftPo();
        $this->assertNotEmpty($this->postJson('/graphql', ['query' => "mutation { updatePurchaseOrder(id: {$po->id}, shipping: 2.505) { id } }"])->json('errors'));
    }

    public function test_mcp_rejects_sub_cent_costs_shipping_and_tax(): void
    {
        foreach ([[['shipping' => 1.005], []], [['tax' => 0.125], []], [[], ['unit_cost' => 2.345]]] as [$overrides, $item]) {
            InventorosServer::actingAs($this->admin)
                ->tool(CreatePurchaseOrderTool::class, $this->payload($overrides, $item + ['unit_cost' => 2]))
                ->assertHasErrors();
        }

        $this->assertSame(0, PurchaseOrder::count());
    }
}
