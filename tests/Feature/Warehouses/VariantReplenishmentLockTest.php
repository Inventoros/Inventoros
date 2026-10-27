<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\VariantReplenishmentPolicy;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\VariantReplenishmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Independent connections exercise database locks and MySQL old snapshots. */
final class VariantReplenishmentLockTest extends TestCase
{
    use WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Requires MySQL/PostgreSQL row locks, covered in database CI.');
        }
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
        $this->setUpWarehouseAccess();
        $this->actingAs($this->admin);
    }

    private function policy(): VariantReplenishmentPolicy
    {
        $this->productA->update(['has_variants' => true]);
        $variant = ProductVariant::create(['organization_id' => $this->organization->id, 'product_id' => $this->productA->id,
            'sku' => 'LOCK-RED', 'title' => 'Red', 'option_values' => [], 'stock' => 0, 'is_active' => true]);
        $supplier = Supplier::create(['organization_id' => $this->organization->id, 'name' => 'Lock supplier', 'currency' => 'USD', 'is_active' => true]);

        return app(VariantReplenishmentService::class)->save($this->admin, ['product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouseB->id, 'location_id' => $this->locationB->id, 'supplier_id' => $supplier->id,
            'reorder_point' => 5, 'target_stock' => 10, 'minimum_order_quantity' => 1, 'unit_cost' => '2.50', 'currency' => 'USD']);
    }

    public function test_an_order_committed_after_the_callers_first_read_still_covers_demand(): void
    {
        $policy = $this->policy();
        $connection = DB::getDefaultConnection();
        config(['database.connections.replenishment_peer' => config('database.connections.'.$connection)]);
        DB::beginTransaction();
        try {
            $this->assertSame(0, PurchaseOrder::count()); // Establish the old MySQL snapshot.
            DB::setDefaultConnection('replenishment_peer');
            $this->assertNotNull(app(VariantReplenishmentService::class)->createDraft($this->admin, $policy));
            DB::setDefaultConnection($connection);
            $this->assertNull(app(VariantReplenishmentService::class)->createDraft($this->admin, $policy));
        } finally {
            DB::setDefaultConnection($connection);
            DB::rollBack();
            DB::disconnect('replenishment_peer');
        }
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_planning_waits_for_the_policy_lock_before_creating_a_document(): void
    {
        $policy = $this->policy();
        config(['database.connections.replenishment_peer' => config('database.connections.'.DB::getDefaultConnection())]);
        $peer = DB::connection('replenishment_peer');
        $peer->beginTransaction();
        $peer->table('variant_replenishment_policies')->where('id', $policy->id)->lockForUpdate()->first();
        DB::statement(DB::getDriverName() === 'mysql' ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '250ms'");
        try {
            app(VariantReplenishmentService::class)->createDraft($this->admin, $policy);
            $this->fail('Planning passed an exclusively held policy lock.');
        } catch (QueryException $error) {
            $this->assertTrue(($error->errorInfo[1] ?? null) === 1205 || ($error->errorInfo[0] ?? null) === '55P03', $error->getMessage());
            $this->assertStringContainsString('variant_replenishment_policies', $error->getSql());
            $this->assertSame(0, PurchaseOrder::count());
        } finally {
            $peer->rollBack();
            DB::disconnect('replenishment_peer');
            DB::statement(DB::getDriverName() === 'mysql' ? 'SET SESSION innodb_lock_wait_timeout = 50' : "SET lock_timeout = '0'");
        }
        $this->assertNotNull(app(VariantReplenishmentService::class)->createDraft($this->admin, $policy));
    }

    public function test_bins_committed_after_the_callers_first_read_cover_demand(): void
    {
        $policy = $this->policy();
        $binId = DB::table('variant_location_stocks')->insertGetId(['organization_id' => $policy->organization_id,
            'product_variant_id' => $policy->product_variant_id, 'location_id' => $policy->location_id,
            'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $connection = DB::getDefaultConnection();
        config(['database.connections.replenishment_peer' => config('database.connections.'.$connection)]);
        DB::beginTransaction();
        try {
            $this->assertSame(2, (int) DB::table('variant_location_stocks')->where('id', $binId)->value('quantity'));
            DB::connection('replenishment_peer')->table('variant_location_stocks')->where('id', $binId)->update(['quantity' => 22]);
            $this->assertNull(app(VariantReplenishmentService::class)->createDraft($this->admin, $policy));
        } finally {
            DB::rollBack();
            DB::disconnect('replenishment_peer');
        }
        $this->assertSame(0, PurchaseOrder::count());
    }
}
