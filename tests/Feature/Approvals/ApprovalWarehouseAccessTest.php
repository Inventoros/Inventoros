<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\AdjustStockTool;
use App\Mcp\Tools\DecideApprovalTool;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAdjustmentRequest;
use App\Models\Inventory\StockTransfer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Warehouses\WarehouseAccessFixture;
use Tests\TestCase;

/**
 * Approval workflows respect warehouse access: a restricted user can only
 * request changes in their own warehouses, a restricted approver only sees
 * and decides requests in theirs, and a held adjustment's bin reaches the
 * ledger when it is approved.
 */
class ApprovalWarehouseAccessTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    private User $approverA;

    private User $approverB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->setUpWarehouseAccess();

        $this->organization->update(['settings' => ['approvals' => [
            'stock_adjustments_enabled' => true,
            'stock_transfers_enabled' => true,
        ]]]);

        $role = Role::create([
            'name' => 'Warehouse approver', 'slug' => 'wh-approver', 'organization_id' => $this->organization->id, 'is_system' => false,
            'permissions' => array_merge($this->stockPermissions, ['approve_stock_adjustments', 'approve_stock_transfers']),
        ]);

        $this->approverA = User::factory()->forOrganization($this->organization->id)->create(['name' => 'Alpha Approver']);
        $this->approverA->roles()->attach($role->id);
        $this->warehouseA->users()->attach($this->approverA->id);

        $this->approverB = User::factory()->forOrganization($this->organization->id)->create(['name' => 'Bravo Approver']);
        $this->approverB->roles()->attach($role->id);
        $this->warehouseB->users()->attach($this->approverB->id);
    }

    private function requestAdjustmentInA(int $quantity = -5): StockAdjustmentRequest
    {
        $this->actingAs($this->restricted)->post(route('stock-adjustments.store'), [
            'product_id' => $this->productA->id, 'type' => 'damage', 'adjustment_quantity' => $quantity,
            'reason' => 'Crushed', 'location_id' => $this->locationA->id,
        ])->assertSessionHas('success');

        return StockAdjustmentRequest::sole();
    }

    private function binQuantity(int $locationId): int
    {
        return (int) ProductLocationStock::where('product_id', $this->productA->id)->where('location_id', $locationId)->value('quantity');
    }

    // ==================== REQUESTING ====================

    public function test_a_restricted_user_cannot_request_an_adjustment_outside_their_warehouses(): void
    {
        $this->actingAs($this->restricted)->post(route('stock-adjustments.store'), [
            'product_id' => $this->productA->id, 'type' => 'damage', 'adjustment_quantity' => -5,
            'reason' => 'x', 'location_id' => $this->locationB->id,
        ])->assertForbidden();

        Sanctum::actingAs($this->restricted);
        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->productA->id, 'quantity' => -5, 'type' => 'damage',
        ])->assertForbidden();

        $this->assertSame(0, StockAdjustmentRequest::count());
    }

    public function test_a_held_adjustment_keeps_its_bin_and_the_ledger_records_it_on_approval(): void
    {
        $request = $this->requestAdjustmentInA();
        $this->assertSame($this->locationA->id, $request->location_id);
        $this->assertSame(60, $this->binQuantity($this->locationA->id));

        $this->actingAs($this->approverA)
            ->post(route('approvals.approve', ['type' => 'stock_adjustment', 'id' => $request->id]))
            ->assertSessionHas('success');

        $this->assertSame(55, $this->binQuantity($this->locationA->id));
        $this->assertSame(40, $this->binQuantity($this->locationB->id));
        $this->assertSame($this->locationA->id, StockAdjustment::sole()->location_id);
    }

    public function test_mcp_request_carries_the_bin_through_to_approval(): void
    {
        $this->app['auth']->forgetGuards();
        InventorosServer::actingAs($this->restricted)
            ->tool(AdjustStockTool::class, ['product_id' => $this->productA->id, 'quantity' => -5, 'type' => 'damage', 'location_id' => $this->locationA->id])
            ->assertOk()
            ->assertSee('pending_approval');

        $request = StockAdjustmentRequest::sole();
        $this->assertSame($this->locationA->id, $request->location_id);

        $this->app['auth']->forgetGuards();
        InventorosServer::actingAs($this->approverA)
            ->tool(DecideApprovalTool::class, ['type' => 'stock_adjustment', 'id' => $request->id, 'decision' => 'approve'])
            ->assertOk();

        $this->assertSame(55, $this->binQuantity($this->locationA->id));
    }

    public function test_graphql_request_takes_a_location_and_enforces_access(): void
    {
        Sanctum::actingAs($this->restricted, ['*']);

        $this->postJson('/graphql', ['query' => sprintf(
            'mutation { requestStockAdjustmentApproval(product_id: %d, quantity: -5, type: "damage", location_id: %d) { id } }',
            $this->productA->id, $this->locationB->id
        )])->assertJsonPath('data.requestStockAdjustmentApproval', null);
        $this->assertSame(0, StockAdjustmentRequest::count());

        $this->postJson('/graphql', ['query' => sprintf(
            'mutation { requestStockAdjustmentApproval(product_id: %d, quantity: -5, type: "damage", location_id: %d) { status } }',
            $this->productA->id, $this->locationA->id
        )])->assertJsonPath('data.requestStockAdjustmentApproval.status', 'pending');

        $this->assertSame($this->locationA->id, StockAdjustmentRequest::sole()->location_id);
    }

    // ==================== DECIDING ====================

    public function test_an_approver_restricted_to_another_warehouse_cannot_see_or_decide_the_adjustment(): void
    {
        $request = $this->requestAdjustmentInA();

        $this->actingAs($this->approverB)
            ->get(route('approvals.index'))
            ->assertInertia(fn (Assert $page) => $page->has('pending', 0)->where('pendingApprovalsCount', 0));

        $this->actingAs($this->approverB)
            ->post(route('approvals.approve', ['type' => 'stock_adjustment', 'id' => $request->id]))
            ->assertForbidden();

        Sanctum::actingAs($this->approverB);
        $this->postJson("/api/v1/approvals/stock_adjustment/{$request->id}/approve")
            ->assertForbidden();

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(60, $this->binQuantity($this->locationA->id));

        $this->actingAs($this->approverA)
            ->get(route('approvals.index'))
            ->assertInertia(fn (Assert $page) => $page->has('pending', 1));
    }

    public function test_only_approvers_with_access_to_the_bin_are_notified(): void
    {
        $this->requestAdjustmentInA();

        $notified = \App\Models\Notification::where('type', 'approval_requested')->pluck('user_id')->all();

        $this->assertContains($this->approverA->id, $notified);
        $this->assertContains($this->admin->id, $notified);
        $this->assertNotContains($this->approverB->id, $notified);
    }

    public function test_transfer_approval_needs_access_to_either_end(): void
    {
        $this->actingAs($this->restricted)->post(route('stock-transfers.store'), [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'items' => [['product_id' => $this->productA->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();
        $transfer = StockTransfer::latest('id')->firstOrFail();
        $this->assertSame('pending', $transfer->approval_status);

        // Bravo is the destination, so Bravo's approver may decide.
        $this->actingAs($this->approverB)
            ->get(route('approvals.index'))
            ->assertInertia(fn (Assert $page) => $page->has('pending', 1));

        // An approver with no warehouse in common may not.
        $outsider = User::factory()->forOrganization($this->organization->id)->create();
        $outsider->roles()->attach(Role::where('slug', 'wh-approver')->value('id'));
        $third = \App\Models\Warehouse::factory()->create(['organization_id' => $this->organization->id, 'code' => 'WH-C']);
        $third->users()->attach($outsider->id);

        $this->actingAs($outsider)
            ->post(route('approvals.approve', ['type' => 'stock_transfer', 'id' => $transfer->id]))
            ->assertForbidden();
        $this->assertSame('pending', $transfer->fresh()->approval_status);

        $this->actingAs($this->approverB)
            ->post(route('approvals.approve', ['type' => 'stock_transfer', 'id' => $transfer->id]))
            ->assertSessionHas('success');
        $this->assertSame('approved', $transfer->fresh()->approval_status);
    }
}
