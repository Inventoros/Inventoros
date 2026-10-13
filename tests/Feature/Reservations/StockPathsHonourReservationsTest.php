<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Exceptions\ApprovalException;
use App\Exceptions\InsufficientStockException;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\AdjustStockTool;
use App\Mcp\Tools\StartWorkOrderTool;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAdjustmentRequest;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\WorkOrder;
use App\Models\Inventory\WorkOrderItem;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ProductLocationStockService;
use App\Services\StockTransferService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\AssertsStockInvariants;
use Tests\TestCase;

/**
 * Every core path that takes stock out, other than orders, checks against
 * available stock: adjustments that refuse negative stock, bin moves and
 * depletion, transfers, approval execution, serial status changes and work
 * orders. Physical corrections may go below available and raise
 * reservation_shortfall instead.
 *
 * The product has 10 on hand, binned 6 in A and 4 in B.
 */
final class StockPathsHonourReservationsTest extends TestCase
{
    use AssertsStockInvariants, RefreshDatabase, ReservationFixtures;

    /** @var array<int, array<int, mixed>> */
    private array $shortfalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReservationWorld();

        add_action('reservation_shortfall', function (...$args) {
            $this->shortfalls[] = $args;
        });
    }

    private function approvals(): ApprovalService
    {
        return app(ApprovalService::class);
    }

    // ---------------------------------------------------------------
    // Adjustments with allowNegative = false
    // ---------------------------------------------------------------

    public function test_a_manual_removal_cannot_take_reserved_units(): void
    {
        $this->hold(7);
        $before = $this->snapshot();

        try {
            $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -4, 'manual', 'Sold at the counter');
            $this->fail('A manual removal above available must be refused.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('only 3 available (10 on hand, 7 reserved)', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot());

        $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -3, 'manual', 'Sold at the counter');
        $this->assertSame(7, $this->stockOf());
        $this->assertBinnedStockBalanced();
        $this->assertSame([], $this->shortfalls);
    }

    public function test_variant_removals_honour_variant_holds(): void
    {
        $variant = $this->makeVariantProduct('SHIRT', 8);
        $this->reservations()->reserve($variant->product, $variant, 6, 'shop', 'CART');

        try {
            StockAdjustment::adjustVariant($variant, -3, 'manual', allowNegative: false, actor: $this->admin);
            $this->fail('A variant removal above available must be refused.');
        } catch (InsufficientStockException) {
        }
        $this->assertSame(8, (int) $variant->fresh()->stock);

        StockAdjustment::adjustVariant($variant, -2, 'manual', allowNegative: false, actor: $this->admin);
        $this->assertSame(6, (int) $variant->fresh()->stock);
    }

    public function test_web_adjustment_honours_holds(): void
    {
        $this->hold(7);
        $before = $this->snapshot();

        $this->actingAs($this->admin)->post(route('stock-adjustments.store'), [
            'product_id' => $this->product->id, 'adjustment_quantity' => -4, 'type' => 'manual', 'reason' => 'Counter sale',
        ])->assertSessionHasErrors('adjustment_quantity');

        $this->assertSame($before, $this->snapshot());
    }

    public function test_rest_adjustment_honours_holds(): void
    {
        $this->hold(7);
        Sanctum::actingAs($this->admin, ['*']);
        $before = $this->snapshot();

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id, 'quantity' => -4, 'type' => 'manual', 'reason' => 'Counter sale',
        ])->assertStatus(422);
        $this->assertSame($before, $this->snapshot());

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id, 'quantity' => -3, 'type' => 'manual', 'reason' => 'Counter sale',
        ])->assertSuccessful();
        $this->assertSame(7, $this->stockOf());
    }

    public function test_mcp_adjustment_honours_holds(): void
    {
        $this->hold(7);
        $before = $this->snapshot();

        InventorosServer::actingAs($this->admin)
            ->tool(AdjustStockTool::class, ['product_id' => $this->product->id, 'quantity' => -4, 'type' => 'manual', 'reason' => 'Counter sale'])
            ->assertHasErrors();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_graphql_adjustment_honours_holds(): void
    {
        $this->hold(7);
        Sanctum::actingAs($this->admin, ['*']);
        $mutation = fn (int $qty, string $type) => sprintf(
            'mutation { createStockAdjustment(product_id: %d, quantity: %d, type: "%s", reason: "gql") { id } }',
            $this->product->id, $qty, $type,
        );
        $before = $this->snapshot();

        $this->assertNotEmpty($this->postJson('/graphql', ['query' => $mutation(-4, 'manual')])->json('errors'));
        $this->assertSame($before, $this->snapshot());

        // A write-off records what happened, holds or not.
        $response = $this->postJson('/graphql', ['query' => $mutation(-4, 'damage')]);
        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertSame(6, $this->stockOf());
        $this->assertCount(1, $this->shortfalls);
    }

    public function test_physical_corrections_may_take_available_below_zero_and_raise_a_shortfall(): void
    {
        $hold = $this->hold(7);

        // Five drills were found broken: that is what is on the shelf now.
        DB::transaction(function () {
            $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -5, 'damage', 'Dropped pallet');
            $this->assertSame([], $this->shortfalls, 'The shortfall is announced after commit.');
        });

        $this->assertSame(5, $this->stockOf());
        $this->assertBinnedStockBalanced();
        $this->assertCount(1, $this->shortfalls);
        [$product, $variant, $shortfall] = $this->shortfalls[0];
        $this->assertSame($this->product->id, $product->id);
        $this->assertNull($variant);
        $this->assertNull($shortfall['location_id']);
        $this->assertSame(5, $shortfall['on_hand']);
        $this->assertSame(7, $shortfall['reserved']);
        $this->assertSame(2, $shortfall['short']);
        $this->assertSame([$hold->id], $shortfall['reservations']->modelKeys());

        // The hold is the owner's to re-plan; core leaves it alone.
        $this->assertTrue($hold->fresh()->isActive());
    }

    public function test_a_recount_is_a_physical_correction(): void
    {
        $this->hold(7);

        $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -6, 'recount', 'Counted 4');

        $this->assertSame(4, $this->stockOf());
        $this->assertCount(1, $this->shortfalls);
    }

    public function test_a_bookkeeping_correction_cannot_take_reserved_units(): void
    {
        $this->hold(7);

        $this->expectException(InsufficientStockException::class);
        $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -4, 'correction', 'Fix a typo');
    }

    public function test_no_shortfall_without_holds_or_within_available(): void
    {
        $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -2, 'damage', 'Broken');
        $this->hold(5);
        $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -3, 'damage', 'Broken');

        $this->assertSame(5, $this->stockOf());
        $this->assertSame([], $this->shortfalls);
    }

    // ---------------------------------------------------------------
    // Bins: applyDelta, consume, move
    // ---------------------------------------------------------------

    public function test_a_bin_removal_cannot_take_units_reserved_in_that_bin(): void
    {
        $this->hold(5, 'BIN', $this->binA->id);
        $before = $this->snapshot();

        try {
            $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -2, 'manual', 'Pick', locationId: $this->binA->id);
            $this->fail('A removal from a held bin above its available units must be refused.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('only 1 available there (6 on hand, 5 reserved)', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot());

        // Bin B is free.
        $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -2, 'manual', 'Pick', locationId: $this->binB->id);
        $this->assertSame(2, $this->binQuantity($this->binB->id));
        $this->assertBinnedStockBalanced();
    }

    public function test_a_bin_write_off_may_go_below_its_holds_and_raises_a_bin_shortfall(): void
    {
        $this->hold(5, 'BIN', $this->binA->id);

        $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -3, 'damage', 'Leak', locationId: $this->binA->id);

        $this->assertSame(3, $this->binQuantity($this->binA->id));
        $binShortfalls = array_values(array_filter($this->shortfalls, fn ($args) => $args[2]['location_id'] === $this->binA->id));
        $this->assertCount(1, $binShortfalls);
        $this->assertSame(2, $binShortfalls[0][2]['short']);
    }

    public function test_apply_delta_honours_bin_holds_by_default(): void
    {
        $this->hold(5, 'BIN', $this->binA->id);
        $bins = app(ProductLocationStockService::class);

        $this->expectException(InsufficientStockException::class);
        DB::transaction(fn () => $bins->applyDelta($this->product, $this->binA->id, -2));
    }

    public function test_depletion_draws_free_bin_units_before_held_ones(): void
    {
        $this->hold(6, 'BIN', $this->binA->id);

        // No bin named: the removal drains bins in fulfilment order, which
        // would start with A (the primary location), but A's units are held.
        $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -4, 'manual', 'Pick');

        $this->assertSame(6, $this->binQuantity($this->binA->id));
        $this->assertSame(0, $this->binQuantity($this->binB->id));
        $this->assertBinnedStockBalanced();
    }

    public function test_a_physical_depletion_draws_held_bin_units_last(): void
    {
        $this->hold(6, 'BIN', $this->binA->id);

        $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -6, 'loss', 'Theft');

        $this->assertSame(4, $this->binQuantity($this->binA->id));
        $this->assertSame(0, $this->binQuantity($this->binB->id));
        $this->assertBinnedStockBalanced();
        $this->assertNotEmpty(array_filter($this->shortfalls, fn ($args) => $args[2]['location_id'] === $this->binA->id));
    }

    // ---------------------------------------------------------------
    // Stock transfers
    // ---------------------------------------------------------------

    private function transfer(int $quantity): StockTransfer
    {
        return app(StockTransferService::class)->create($this->org->id, $this->admin, [
            'from_location_id' => $this->binA->id,
            'to_location_id' => $this->binB->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
        ]);
    }

    public function test_a_transfer_cannot_move_units_reserved_in_the_source_bin(): void
    {
        $this->hold(4, 'BIN', $this->binA->id);
        $transfer = $this->transfer(3);
        $before = $this->snapshot();

        try {
            app(StockTransferService::class)->complete($transfer, $this->admin);
            $this->fail('Held bin units must not be transferred.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('pending', $transfer->fresh()->status);

        app(StockTransferService::class)->complete($this->transfer(2), $this->admin);
        $this->assertSame(4, $this->binQuantity($this->binA->id));
        $this->assertSame(6, $this->binQuantity($this->binB->id));
    }

    public function test_a_product_level_hold_does_not_block_a_transfer(): void
    {
        // Every unit is promised, but not from any particular bin.
        $this->hold(10);

        app(StockTransferService::class)->complete($this->transfer(6), $this->admin);

        $this->assertSame(0, $this->binQuantity($this->binA->id));
        $this->assertSame(10, $this->binQuantity($this->binB->id));
        $this->assertSame(10, $this->stockOf());
    }

    public function test_web_and_rest_transfer_completion_honour_bin_holds(): void
    {
        $this->hold(4, 'BIN', $this->binA->id);
        $before = $this->snapshot();

        $web = $this->transfer(3);
        $this->actingAs($this->admin)->post(route('stock-transfers.complete', $web));
        $this->assertSame('pending', $web->fresh()->status);

        $rest = $this->transfer(3);
        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/v1/stock-transfers/{$rest->id}/complete")->assertStatus(422);
        $this->assertSame('pending', $rest->fresh()->status);

        $this->assertSame($before, $this->snapshot());
    }

    // ---------------------------------------------------------------
    // Approval execution
    // ---------------------------------------------------------------

    public function test_an_approved_adjustment_is_rechecked_against_available(): void
    {
        $settings = $this->org->settings ?? [];
        $settings['approvals'] = ['stock_adjustments_enabled' => true];
        $this->org->update(['settings' => $settings]);

        $request = $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -4, 'manual', 'Counter sale');
        $this->assertInstanceOf(StockAdjustmentRequest::class, $request);

        // A reservation lands while the request waits.
        $this->hold(7);
        $before = $this->snapshot();

        $approver = User::create([
            'name' => 'Abe', 'email' => 'abe@hire.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        try {
            $this->approvals()->approve($approver, ApprovalService::STOCK_ADJUSTMENT, $request->id);
            $this->fail('Approval must re-check available stock.');
        } catch (ApprovalException $e) {
            $this->assertStringContainsString('available', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_a_request_that_could_never_be_approved_is_refused_up_front(): void
    {
        $settings = $this->org->settings ?? [];
        $settings['approvals'] = ['stock_adjustments_enabled' => true];
        $this->org->update(['settings' => $settings]);
        $this->hold(7);

        $this->expectException(InsufficientStockException::class);
        $this->approvals()->submitStockAdjustment($this->admin, $this->product, null, -4, 'manual', 'Counter sale');
    }

    // ---------------------------------------------------------------
    // Serials
    // ---------------------------------------------------------------

    public function test_a_held_serial_cannot_be_sold_reserved_or_written_off_by_hand(): void
    {
        $camera = $this->makeProduct('CAMERA', 2, [], ['tracking_type' => 'serial']);
        $held = ProductSerial::create(['organization_id' => $this->org->id, 'product_id' => $camera->id, 'serial_number' => 'CAM-1', 'status' => 'available']);
        $free = ProductSerial::create(['organization_id' => $this->org->id, 'product_id' => $camera->id, 'serial_number' => 'CAM-2', 'status' => 'available']);
        $this->reservations()->reserve($camera, null, 1, 'rental', 'B-1', serial: $held);
        Sanctum::actingAs($this->admin, ['*']);

        foreach (['sold', 'reserved', 'damaged'] as $status) {
            $this->putJson("/api/v1/products/{$camera->id}/serials/{$held->id}", ['status' => $status])
                ->assertStatus(409)
                ->assertJsonPath('error', 'serial_reserved');
        }
        $this->assertSame('available', $held->fresh()->status);

        $this->putJson("/api/v1/products/{$camera->id}/serials/{$free->id}", ['status' => 'damaged'])->assertOk();

        // Released, it can be changed again.
        $this->reservations()->release('rental', 'B-1', $this->org->id);
        $this->putJson("/api/v1/products/{$camera->id}/serials/{$held->id}", ['status' => 'damaged'])->assertOk();
    }

    // ---------------------------------------------------------------
    // Work orders
    // ---------------------------------------------------------------

    private function workOrder(string $status = 'draft', int $required = 4): WorkOrder
    {
        $assembly = $this->makeProduct('KIT', 0, [], ['type' => 'assembly']);

        $workOrder = WorkOrder::create([
            'organization_id' => $this->org->id,
            'product_id' => $assembly->id,
            'created_by' => $this->admin->id,
            'work_order_number' => WorkOrder::generateWorkOrderNumber($this->org->id),
            'quantity' => 1,
            'status' => $status,
            'started_at' => $status === 'in_progress' ? now() : null,
        ]);

        WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'product_id' => $this->product->id,
            'quantity_required' => $required,
            'quantity_consumed' => 0,
        ]);

        return $workOrder;
    }

    public function test_starting_a_work_order_checks_component_availability(): void
    {
        $this->hold(7);
        $workOrder = $this->workOrder();

        try {
            app(WorkOrderService::class)->start($workOrder);
            $this->fail('A work order whose components are promised must not start.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('Available: 3', $e->getMessage());
        }
        $this->assertSame('draft', $workOrder->fresh()->status);

        // Web and REST share the service.
        $this->actingAs($this->admin)->post(route('work-orders.start', $workOrder))->assertSessionHas('error');
        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/v1/work-orders/{$workOrder->id}/start")->assertStatus(422);
        $this->assertSame('draft', $workOrder->fresh()->status);
    }

    public function test_mcp_start_work_order_checks_component_availability(): void
    {
        $this->hold(7);
        $workOrder = $this->workOrder();

        InventorosServer::actingAs($this->admin)
            ->tool(StartWorkOrderTool::class, ['id' => $workOrder->id])
            ->assertHasErrors();

        $this->assertSame('draft', $workOrder->fresh()->status);
    }

    public function test_completing_a_work_order_cannot_consume_promised_components(): void
    {
        $workOrder = $this->workOrder('in_progress');
        $this->hold(7);
        $before = $this->snapshot();

        $this->actingAs($this->admin)->post(route('work-orders.complete', $workOrder))->assertSessionHas('error');
        $this->assertSame('in_progress', $workOrder->fresh()->status);
        $this->assertSame($before, $this->snapshot());

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/v1/work-orders/{$workOrder->id}/complete")->assertStatus(422)->assertJsonPath('error', 'insufficient_stock');
        $this->assertSame('in_progress', $workOrder->fresh()->status);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_completing_a_work_order_within_available_draws_free_bin_units(): void
    {
        $workOrder = $this->workOrder('in_progress');
        $this->hold(6, 'BIN', $this->binA->id);

        $this->actingAs($this->admin)->post(route('work-orders.complete', $workOrder))->assertSessionHas('success');

        $this->assertSame('completed', $workOrder->fresh()->status);
        $this->assertSame(6, $this->stockOf());
        $this->assertSame(6, $this->binQuantity($this->binA->id));
        $this->assertSame(0, $this->binQuantity($this->binB->id));
        $this->assertBinnedStockBalanced();
    }
}
