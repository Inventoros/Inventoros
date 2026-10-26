<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\WorkOrder;
use App\Models\Inventory\WorkOrderItem;
use App\Services\InventoryResetService;
use App\Services\InventoryWorkflowLock;
use App\Services\StockAuditService;
use App\Services\StockTransferService;
use App\Services\WorkOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Real independent database connections, rather than stale model simulation. */
final class InventoryWorkflowLockTest extends TestCase
{
    use WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(static function (): void {
            // These fixtures must commit so the second connection can see
            // them. Later RefreshDatabase classes need a fresh database.
            RefreshDatabaseState::$migrated = false;
        });
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Requires database row locks (covered by both database CI jobs).');
        }
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }
    }

    public function test_workflows_wait_for_the_shared_gate_before_mutating_stock_or_state(): void
    {
        foreach (['audit', 'production', 'transfer', 'reset'] as $workflow) {
            $this->setUpWarehouseAccess();
            $this->assertWorkflowWaits($workflow);
        }
    }

    private function assertWorkflowWaits(string $workflow): void
    {
        $audit = $this->makeAudit($this->locationA, 'Gate check');
        $audit->items()->create(['product_id' => $this->productA->id, 'location_id' => $this->locationA->id, 'system_quantity' => 60]);
        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->productB->id,
            'created_by' => $this->admin->id, 'work_order_number' => 'WO-GATE', 'quantity' => 1, 'status' => 'draft',
        ]);
        WorkOrderItem::create(['work_order_id' => $workOrder->id, 'product_id' => $this->productA->id,
            'quantity_required' => 1, 'quantity_consumed' => 0]);
        $reset = app(InventoryResetService::class);
        $signature = $reset->preview($this->admin)['signature'];
        $operation = fn () => match ($workflow) {
            'audit' => app(StockAuditService::class)->start($audit, $this->admin),
            'production' => app(WorkOrderService::class)->start($workOrder),
            'transfer' => app(StockTransferService::class)->create($this->organization->id, $this->admin, [
                'from_location_id' => $this->locationA->id, 'to_location_id' => $this->locationB->id,
                'items' => [['product_id' => $this->productA->id, 'quantity' => 1]],
            ]),
            'reset' => $reset->reset($this->admin, $signature, 'Gate check'),
        };

        DB::transaction(fn () => InventoryWorkflowLock::acquire($this->organization->id));
        config(['database.connections.inventory_gate_peer' => config('database.connections.'.DB::getDefaultConnection())]);
        $peer = DB::connection('inventory_gate_peer');
        $peer->beginTransaction();
        $peer->table('inventory_workflow_locks')->where('organization_id', $this->organization->id)->lockForUpdate()->first();
        DB::statement(DB::getDriverName() === 'mysql' ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '250ms'");
        try {
            $operation();
            $this->fail('A concurrent workflow passed an exclusively held gate.');
        } catch (QueryException $error) {
            $this->assertTrue(($error->errorInfo[1] ?? null) === 1205 || ($error->errorInfo[0] ?? null) === '55P03', $error->getMessage());
            $this->assertStringContainsString('inventory_workflow_locks', $error->getSql());
            $this->assertSame(100, $this->productA->fresh()->stock);
            $this->assertSame('draft', $audit->fresh()->status);
            $this->assertSame('draft', $workOrder->fresh()->status);
            $this->assertSame(0, DB::table('stock_transfers')->where('organization_id', $this->organization->id)->count());
            $this->assertSame(0, DB::table('stock_adjustments')->where('organization_id', $this->organization->id)->count());
        } finally {
            $peer->rollBack();
            DB::disconnect('inventory_gate_peer');
            DB::statement(DB::getDriverName() === 'mysql' ? 'SET SESSION innodb_lock_wait_timeout = 50' : "SET lock_timeout = '0'");
        }
        $operation();
        if ($workflow === 'reset') {
            // An audit starting after the reset snapshots the zero balances.
            app(StockAuditService::class)->start($audit, $this->admin);
            $this->assertSame(0, $audit->items()->firstOrFail()->system_quantity);
            $this->assertGreaterThan(0, StockAdjustment::count());
        } else {
            $this->assertTrue($reset->preview($this->admin)['blocked']);
        }
    }
}
