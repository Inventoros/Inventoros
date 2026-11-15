<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\DeleteWorkOrderTool;
use App\Mcp\Tools\ListWorkOrdersTool;
use App\Mcp\Tools\StartWorkOrderTool;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\VariantLocationStock;
use App\Models\Inventory\WorkOrder;
use App\Models\Warehouse;
use App\Services\WorkOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Work orders are bound to a warehouse and move stock out of component bins
 * and into the output's bin. A warehouse-restricted user must not see, drive
 * or draw stock through a work order outside their assigned warehouses.
 */
final class WorkOrderWarehouseAccessTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    private Product $assemblyA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();

        // The output is stocked in warehouse A; one productA per unit.
        $this->assemblyA = $this->makeAssembly('Alpha Assembly', 'ASM-A', $this->locationA, $this->productA);
    }

    private function makeAssembly(string $name, string $sku, ?ProductLocation $location, Product $component): Product
    {
        $assembly = $this->makeProduct($name, $sku, $location, 0);
        $assembly->update(['type' => 'assembly']);
        ProductComponent::create([
            'parent_product_id' => $assembly->id,
            'component_product_id' => $component->id,
            'quantity' => 1,
            'sort_order' => 0,
        ]);

        return $assembly->fresh();
    }

    private function workOrder(?Warehouse $warehouse, int $quantity = 5, string $status = 'draft', ?Product $assembly = null, array $extra = []): WorkOrder
    {
        $workOrder = app(WorkOrderService::class)->create($this->admin, [
            'product_id' => ($assembly ?? $this->assemblyA)->id,
            'quantity' => $quantity,
            'warehouse_id' => $warehouse?->id,
        ] + $extra);

        if ($status !== 'draft') {
            $workOrder->update(['status' => $status]);
        }

        return $workOrder->fresh();
    }

    private function bin(Product $product, ProductLocation $location): int
    {
        return (int) ProductLocationStock::where('product_id', $product->id)->where('location_id', $location->id)->value('quantity');
    }

    private function assertComponentStockUntouched(): void
    {
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertSame(60, $this->bin($this->productA, $this->locationA));
        $this->assertSame(40, $this->bin($this->productA, $this->locationB));
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    private function assertRefused(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('A warehouse-restricted work order action must be refused.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    // ---- Web -----------------------------------------------------------

    public function test_web_cannot_create_a_work_order_in_an_unassigned_warehouse(): void
    {
        $this->actingAs($this->restricted)->post(route('work-orders.store'), [
            'product_id' => $this->assemblyA->id, 'quantity' => 1, 'warehouse_id' => $this->warehouseB->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_web_restricted_user_must_name_a_warehouse(): void
    {
        $this->actingAs($this->restricted)->post(route('work-orders.store'), [
            'product_id' => $this->assemblyA->id, 'quantity' => 1,
        ])->assertSessionHasErrors('warehouse_id');

        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_web_can_create_a_work_order_in_an_assigned_warehouse(): void
    {
        $this->actingAs($this->restricted)->post(route('work-orders.store'), [
            'product_id' => $this->assemblyA->id, 'quantity' => 1, 'warehouse_id' => $this->warehouseA->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('work_orders', ['warehouse_id' => $this->warehouseA->id, 'created_by' => $this->restricted->id]);
    }

    public function test_web_cannot_view_or_drive_a_work_order_in_an_unassigned_warehouse(): void
    {
        $draft = $this->workOrder($this->warehouseB);
        $running = $this->workOrder($this->warehouseB, status: 'in_progress');
        $this->actingAs($this->restricted);

        $this->get(route('work-orders.show', $draft))->assertForbidden();
        $this->post(route('work-orders.start', $draft))->assertForbidden();
        $this->post(route('work-orders.complete', $running))->assertForbidden();
        $this->post(route('work-orders.cancel', $draft))->assertForbidden();
        $this->delete(route('work-orders.destroy', $draft))->assertForbidden();

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('in_progress', $running->fresh()->status);
        $this->assertComponentStockUntouched();
    }

    public function test_web_index_and_create_form_hide_unassigned_warehouses(): void
    {
        $mine = $this->workOrder($this->warehouseA);
        $theirs = $this->workOrder($this->warehouseB);
        $floating = $this->workOrder(null);

        $this->actingAs($this->restricted)->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('workOrders.data', 1)
                ->where('workOrders.data.0.id', $mine->id));

        $this->actingAs($this->restricted)->get(route('work-orders.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('warehouses', 1)
                ->where('warehouses.0.id', $this->warehouseA->id));

        $this->actingAs($this->admin)->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('workOrders.data', 3));
        $this->assertNotNull($theirs->id);
        $this->assertNotNull($floating->id);
    }

    // ---- REST ----------------------------------------------------------

    public function test_api_cannot_create_view_or_drive_a_work_order_in_an_unassigned_warehouse(): void
    {
        $draft = $this->workOrder($this->warehouseB);
        $running = $this->workOrder($this->warehouseB, status: 'in_progress');
        Sanctum::actingAs($this->restricted, ['*']);

        $this->postJson('/api/v1/work-orders', [
            'product_id' => $this->assemblyA->id, 'quantity' => 1, 'warehouse_id' => $this->warehouseB->id,
        ])->assertForbidden();
        $this->getJson("/api/v1/work-orders/{$draft->id}")->assertForbidden();
        $this->postJson("/api/v1/work-orders/{$draft->id}/start")->assertForbidden();
        $this->postJson("/api/v1/work-orders/{$running->id}/complete")->assertForbidden();
        $this->postJson("/api/v1/work-orders/{$draft->id}/cancel")->assertForbidden();
        $this->deleteJson("/api/v1/work-orders/{$draft->id}")->assertForbidden();

        $this->assertDatabaseCount('work_orders', 2);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('in_progress', $running->fresh()->status);
        $this->assertComponentStockUntouched();
    }

    public function test_api_index_lists_only_assigned_warehouses(): void
    {
        $mine = $this->workOrder($this->warehouseA);
        $this->workOrder($this->warehouseB);
        $this->workOrder(null);

        Sanctum::actingAs($this->restricted, ['*']);
        $this->getJson('/api/v1/work-orders')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        Sanctum::actingAs($this->unassigned, ['*']);
        $this->getJson('/api/v1/work-orders')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_api_restricted_user_completes_in_assigned_warehouse_from_assigned_bins_only(): void
    {
        $workOrder = $this->workOrder($this->warehouseA, quantity: 5, status: 'in_progress');
        Sanctum::actingAs($this->restricted, ['*']);

        $this->postJson("/api/v1/work-orders/{$workOrder->id}/complete")->assertOk();

        $this->assertSame('completed', $workOrder->fresh()->status);
        $this->assertSame(95, $this->productA->fresh()->stock);
        $this->assertSame(55, $this->bin($this->productA, $this->locationA));
        $this->assertSame(40, $this->bin($this->productA, $this->locationB));
        $this->assertSame(5, $this->bin($this->assemblyA, $this->locationA));
    }

    // ---- Service (shared by web, REST and MCP) -------------------------

    public function test_completion_never_falls_back_to_another_warehouses_bins(): void
    {
        // warehouseB outranks A, and A's bin cannot cover the order alone.
        $this->warehouseB->update(['priority' => 100]);
        $workOrder = $this->workOrder($this->warehouseA, quantity: 61, status: 'in_progress');

        $this->actingAs($this->restricted);
        $this->assertRefused(fn () => app(WorkOrderService::class)->complete($workOrder, [], $this->restricted));

        $this->assertSame('in_progress', $workOrder->fresh()->status);
        $this->assertComponentStockUntouched();
    }

    public function test_variant_component_draws_only_from_assigned_bins(): void
    {
        $gadget = $this->makeProduct('Gadget', 'GADGET', $this->locationA, 0);
        $gadget->update(['has_variants' => true]);
        $variant = ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $gadget->id,
            'option_values' => ['Color' => 'Red'], 'sku' => 'GADGET-RED', 'title' => 'Red', 'stock' => 30, 'is_active' => true,
        ]);
        VariantLocationStock::updateOrCreate(['product_variant_id' => $variant->id, 'location_id' => $this->locationA->id], ['organization_id' => $this->organization->id, 'quantity' => 10]);
        VariantLocationStock::create(['organization_id' => $this->organization->id, 'product_variant_id' => $variant->id, 'location_id' => $this->locationB->id, 'quantity' => 20]);
        $this->warehouseB->update(['priority' => 100]);

        $assembly = $this->makeAssembly('Gadget Assembly', 'ASM-G', $this->locationA, $gadget);
        $this->actingAs($this->restricted);
        $variantBin = fn (ProductLocation $location) => (int) VariantLocationStock::where('product_variant_id', $variant->id)->where('location_id', $location->id)->value('quantity');

        $tooMany = $this->workOrder($this->warehouseA, quantity: 11, status: 'in_progress', assembly: $assembly, extra: [
            'component_variants' => [['product_id' => $gadget->id, 'product_variant_id' => $variant->id]],
        ]);
        $this->assertRefused(fn () => app(WorkOrderService::class)->complete($tooMany, [], $this->restricted));
        $this->assertSame(30, $variant->fresh()->stock);
        $this->assertSame(10, $variantBin($this->locationA));
        $this->assertSame(20, $variantBin($this->locationB));

        $fits = $this->workOrder($this->warehouseA, quantity: 4, status: 'in_progress', assembly: $assembly, extra: [
            'component_variants' => [['product_id' => $gadget->id, 'product_variant_id' => $variant->id]],
        ]);
        app(WorkOrderService::class)->complete($fits, [], $this->restricted);
        $this->assertSame(26, $variant->fresh()->stock);
        $this->assertSame(6, $variantBin($this->locationA));
        $this->assertSame(20, $variantBin($this->locationB));
    }

    public function test_output_cannot_be_booked_into_an_unassigned_warehouse(): void
    {
        $assemblyB = $this->makeAssembly('Bravo Assembly', 'ASM-B', $this->locationB, $this->productA);
        $workOrder = $this->workOrder($this->warehouseA, quantity: 2, status: 'in_progress', assembly: $assemblyB);
        $this->actingAs($this->restricted);

        $this->assertRefused(fn () => app(WorkOrderService::class)->complete($workOrder, [], $this->restricted));

        $this->assertSame('in_progress', $workOrder->fresh()->status);
        $this->assertSame(0, $assemblyB->fresh()->stock);
        $this->assertComponentStockUntouched();
    }

    public function test_unrestricted_admin_completion_is_unchanged(): void
    {
        $workOrder = $this->workOrder($this->warehouseA, quantity: 70, status: 'in_progress');
        $this->actingAs($this->admin);

        app(WorkOrderService::class)->complete($workOrder, [], $this->admin);

        $this->assertSame('completed', $workOrder->fresh()->status);
        $this->assertSame(30, $this->productA->fresh()->stock);
        $this->assertSame(30, $this->bin($this->productA, $this->locationA) + $this->bin($this->productA, $this->locationB));
        $this->assertSame(70, $this->assemblyA->fresh()->stock);
    }

    // ---- MCP -----------------------------------------------------------

    public function test_mcp_hides_and_refuses_work_orders_in_unassigned_warehouses(): void
    {
        $mine = $this->workOrder($this->warehouseA);
        $theirs = $this->workOrder($this->warehouseB);

        $list = InventorosServer::actingAs($this->restricted)->tool(ListWorkOrdersTool::class, []);
        $list->assertOk()->assertSee($mine->work_order_number)->assertDontSee($theirs->work_order_number);

        InventorosServer::actingAs($this->restricted)->tool(StartWorkOrderTool::class, ['id' => $theirs->id])->assertHasErrors();
        InventorosServer::actingAs($this->restricted)->tool(DeleteWorkOrderTool::class, ['id' => $theirs->id])->assertHasErrors();

        $this->assertSame('draft', $theirs->fresh()->status);
        $this->assertNotNull(WorkOrder::find($theirs->id));
    }
}
