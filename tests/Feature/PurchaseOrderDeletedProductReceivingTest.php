<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\ReceivePurchaseOrderTool;
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
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PurchaseOrderDeletedProductReceivingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private ProductLocation $location;

    private PurchaseOrder $purchaseOrder;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Receiving', 'email' => 'receiving@example.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'organization_id' => $this->organization->id, 'role' => 'admin',
            'name' => 'Receiver', 'email' => 'receiver@example.test', 'password' => bcrypt('password'),
        ]);
        $supplier = Supplier::create([
            'organization_id' => $this->organization->id, 'name' => 'Supplier', 'is_active' => true,
        ]);
        $this->location = ProductLocation::create([
            'organization_id' => $this->organization->id, 'name' => 'Receiving shelf', 'code' => 'RECEIVE', 'is_active' => true,
        ]);
        $this->purchaseOrder = PurchaseOrder::create([
            'organization_id' => $this->organization->id, 'supplier_id' => $supplier->id,
            'created_by' => $this->admin->id, 'po_number' => 'PO-UNAVAILABLE',
            'status' => PurchaseOrder::STATUS_SENT, 'order_date' => now()->toDateString(),
            'subtotal' => 50, 'tax' => 0, 'total' => 50, 'currency' => 'USD',
        ]);
    }

    public function test_web_receiving_reports_an_unavailable_product_instead_of_success(): void
    {
        [$product, $item] = $this->line('DELETED');
        $product->delete();

        $this->actingAs($this->admin)
            ->from(route('purchase-orders.receive', $this->purchaseOrder))
            ->post(route('purchase-orders.process-receiving', $this->purchaseOrder), [
                'items' => [['id' => $item->id, 'quantity_to_receive' => 10]],
            ])
            ->assertRedirect(route('purchase-orders.receive', $this->purchaseOrder))
            ->assertSessionHasErrors('items')
            ->assertSessionMissing('success');

        $this->assertUnreceived($item);
        $this->assertSame(5, (int) $product->fresh()->stock);
    }

    public function test_api_receiving_rejects_a_deleted_product_without_recording_a_receipt(): void
    {
        [, $item] = $this->line('DELETED');
        $item->product->delete();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/purchase-orders/{$this->purchaseOrder->id}/receive", [
            'items' => [['id' => $item->id, 'quantity_to_receive' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertUnreceived($item);
    }

    public function test_an_unavailable_line_rolls_back_other_lines_in_the_same_delivery(): void
    {
        [$available, $first] = $this->line('AVAILABLE');
        [, $deleted] = $this->line('DELETED');
        $deleted->product->delete();
        Sanctum::actingAs($this->admin);
        $receivedEvents = [];
        add_action('purchase_order_received', function (...$arguments) use (&$receivedEvents): void {
            $receivedEvents[] = $arguments;
        });
        $stockEvents = [];
        add_action('stock_changed', function (...$arguments) use (&$stockEvents): void {
            $stockEvents[] = $arguments;
        });

        $this->postJson("/api/v1/purchase-orders/{$this->purchaseOrder->id}/receive", [
            'items' => [
                ['id' => $first->id, 'quantity_to_receive' => 10],
                ['id' => $deleted->id, 'quantity_to_receive' => 10],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertUnreceived($first);
        $this->assertUnreceived($deleted);
        $this->assertSame(5, (int) $available->fresh()->stock);
        $this->assertSame(5, (int) ProductLocationStock::where('product_id', $available->id)->sum('quantity'));
        $this->assertDatabaseCount('supplier_price_history', 0);
        $this->assertSame([], $receivedEvents);
        $this->assertSame([], $stockEvents);
    }

    public function test_graphql_receiving_surfaces_the_unavailable_product_as_validation(): void
    {
        [$product, $item] = $this->line('GRAPHQL-DELETED');
        $product->delete();

        $this->actingAs($this->admin)->postJson('/graphql', [
            'query' => "mutation { receivePurchaseOrder(id: {$this->purchaseOrder->id}, items: [{id: {$item->id}, quantity_to_receive: 10}]) { id status } }",
        ])->assertOk()->assertJsonPath('errors.0.message', 'validation');

        $this->assertUnreceived($item);
    }

    public function test_mcp_receiving_returns_an_error_for_an_unavailable_product(): void
    {
        [$product, $item] = $this->line('MCP-DELETED');
        $product->delete();

        InventorosServer::actingAs($this->admin)
            ->tool(ReceivePurchaseOrderTool::class, [
                'id' => $this->purchaseOrder->id,
                'items' => [['id' => $item->id, 'quantity_to_receive' => 10]],
            ])
            ->assertHasErrors(['no longer available']);

        $this->assertUnreceived($item);
    }

    public function test_receiving_a_deleted_variant_does_not_credit_the_parent_or_report_success(): void
    {
        [$product, $item] = $this->line('VARIANT');
        $product->update(['has_variants' => true]);
        $variant = ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $product->id,
            'sku' => 'VARIANT-S', 'title' => 'Small', 'option_values' => ['Size' => 'Small'],
            'price' => 10, 'stock' => 2, 'min_stock' => 0, 'is_active' => true,
        ]);
        $item->update(['product_variant_id' => $variant->id]);
        $variant->delete();
        $this->actingAs($this->admin);

        try {
            app(PurchaseOrderService::class)->receive($this->purchaseOrder, $this->admin, [
                ['id' => $item->id, 'quantity_to_receive' => 10],
            ]);
            $this->fail('An unavailable variant must not be reported as received.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertUnreceived($item);
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) ProductVariant::withTrashed()->findOrFail($variant->id)->stock);
    }

    public function test_restoring_the_product_allows_the_delivery_to_be_received_normally(): void
    {
        [$product, $item] = $this->line('RESTORED');
        $product->delete();
        Sanctum::actingAs($this->admin);
        $payload = ['items' => [['id' => $item->id, 'quantity_to_receive' => 10]]];

        $this->postJson("/api/v1/purchase-orders/{$this->purchaseOrder->id}/receive", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $product->restore();

        $this->postJson("/api/v1/purchase-orders/{$this->purchaseOrder->id}/receive", $payload)
            ->assertOk()->assertJsonPath('message', 'Items received successfully');

        $this->assertSame(10, $item->fresh()->quantity_received);
        $this->assertSame(15, (int) $product->fresh()->stock);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $this->purchaseOrder->fresh()->status);
        $this->assertDatabaseCount('stock_adjustments', 1);
    }

    /** @return array{Product, PurchaseOrderItem} */
    private function line(string $sku): array
    {
        $product = Product::create([
            'organization_id' => $this->organization->id, 'name' => $sku, 'sku' => $sku,
            'location_id' => $this->location->id, 'price' => 10, 'currency' => 'USD',
            'stock' => 5, 'min_stock' => 0, 'is_active' => true,
        ]);
        ProductLocationStock::create([
            'organization_id' => $this->organization->id, 'product_id' => $product->id,
            'location_id' => $this->location->id, 'quantity' => 5,
        ]);
        $item = $this->purchaseOrder->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $sku,
            'quantity_ordered' => 10, 'quantity_received' => 0,
            'unit_cost' => 5, 'subtotal' => 50, 'tax' => 0, 'total' => 50,
        ]);

        return [$product, $item];
    }

    private function assertUnreceived(PurchaseOrderItem $item): void
    {
        $this->assertSame(0, $item->fresh()->quantity_received);
        $this->assertSame(PurchaseOrder::STATUS_SENT, $this->purchaseOrder->fresh()->status);
        $this->assertNull($this->purchaseOrder->fresh()->received_at);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }
}
