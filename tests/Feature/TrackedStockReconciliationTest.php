<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductSerial;
use App\Models\User;
use App\Services\TrackedStockReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * products.stock and the batch/serial tracking records are maintained
 * independently, so they can silently drift. These tests cover the
 * reconciliation that surfaces (and optionally corrects) that drift.
 */
class TrackedStockReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function org(): Organization
    {
        return Organization::create([
            'name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
    }

    public function test_it_reports_drift_between_recorded_stock_and_tracking_records(): void
    {
        $org = $this->org();

        // Batch-tracked: recorded 5, but batches sum to 8.
        $batched = Product::create([
            'organization_id' => $org->id, 'sku' => 'B-1', 'name' => 'Batched',
            'price' => 10, 'currency' => 'USD', 'stock' => 5,
            'tracking_type' => 'batch', 'is_active' => true,
        ]);
        ProductBatch::create(['organization_id' => $org->id, 'product_id' => $batched->id, 'batch_number' => 'L1', 'quantity' => 3]);
        ProductBatch::create(['organization_id' => $org->id, 'product_id' => $batched->id, 'batch_number' => 'L2', 'quantity' => 5]);

        // Serial-tracked: recorded 2, but only 1 serial is available (the other is sold).
        $serialed = Product::create([
            'organization_id' => $org->id, 'sku' => 'S-1', 'name' => 'Serialed',
            'price' => 20, 'currency' => 'USD', 'stock' => 2,
            'tracking_type' => 'serial', 'is_active' => true,
        ]);
        ProductSerial::create(['organization_id' => $org->id, 'product_id' => $serialed->id, 'serial_number' => 'SN1', 'status' => 'available']);
        ProductSerial::create(['organization_id' => $org->id, 'product_id' => $serialed->id, 'serial_number' => 'SN2', 'status' => 'sold']);

        // In-sync batch product: must NOT appear.
        $ok = Product::create([
            'organization_id' => $org->id, 'sku' => 'B-OK', 'name' => 'OK',
            'price' => 10, 'currency' => 'USD', 'stock' => 4,
            'tracking_type' => 'batch', 'is_active' => true,
        ]);
        ProductBatch::create(['organization_id' => $org->id, 'product_id' => $ok->id, 'batch_number' => 'L3', 'quantity' => 4]);

        // Untracked product: must never be considered even though it has "stock".
        Product::create([
            'organization_id' => $org->id, 'sku' => 'N-1', 'name' => 'None',
            'price' => 10, 'currency' => 'USD', 'stock' => 99,
            'tracking_type' => 'none', 'is_active' => true,
        ]);

        $rows = app(TrackedStockReconciliationService::class)
            ->discrepancies($org->id)
            ->keyBy(fn (array $r) => $r['product']->id);

        $this->assertCount(2, $rows);

        $this->assertSame('batch', $rows[$batched->id]['tracking_type']);
        $this->assertSame(5, $rows[$batched->id]['recorded_stock']);
        $this->assertSame(8, $rows[$batched->id]['tracked_total']);
        $this->assertSame(3, $rows[$batched->id]['difference']);

        $this->assertSame('serial', $rows[$serialed->id]['tracking_type']);
        $this->assertSame(2, $rows[$serialed->id]['recorded_stock']);
        $this->assertSame(1, $rows[$serialed->id]['tracked_total']);
        $this->assertSame(-1, $rows[$serialed->id]['difference']);
    }

    public function test_reconcile_corrects_recorded_stock_via_an_audited_recount_adjustment(): void
    {
        Mail::fake();
        Notification::fake();
        $org = $this->org();
        $actor = User::create([
            'name' => 'Owner', 'email' => 'owner@o.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);

        $batched = Product::create([
            'organization_id' => $org->id, 'sku' => 'B-1', 'name' => 'Batched',
            'price' => 10, 'currency' => 'USD', 'stock' => 5, 'min_stock' => 0,
            'tracking_type' => 'batch', 'is_active' => true,
        ]);
        ProductBatch::create(['organization_id' => $org->id, 'product_id' => $batched->id, 'batch_number' => 'L1', 'quantity' => 8]);

        $fixed = app(TrackedStockReconciliationService::class)->reconcile($org->id, $actor);

        $this->assertSame(1, $fixed);
        $this->assertSame(8, $batched->fresh()->stock);

        // The correction is traceable (attributed to the actor), not a silent
        // overwrite of products.stock.
        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $batched->id,
            'user_id' => $actor->id,
            'type' => 'recount',
            'quantity_before' => 5,
            'quantity_after' => 8,
            'adjustment_quantity' => 3,
        ]);

        // Nothing left to reconcile once corrected.
        $this->assertSame(0, app(TrackedStockReconciliationService::class)->reconcile($org->id, $actor));
    }

    public function test_command_reports_without_fix_and_leaves_stock_untouched(): void
    {
        $org = $this->org();
        $batched = Product::create([
            'organization_id' => $org->id, 'sku' => 'B-1', 'name' => 'Batched',
            'price' => 10, 'currency' => 'USD', 'stock' => 5,
            'tracking_type' => 'batch', 'is_active' => true,
        ]);
        ProductBatch::create(['organization_id' => $org->id, 'product_id' => $batched->id, 'batch_number' => 'L1', 'quantity' => 8]);

        $this->artisan('inventory:reconcile-tracked-stock')
            ->assertExitCode(0);

        // Report-only: stock is not corrected without --fix.
        $this->assertSame(5, $batched->fresh()->stock);
    }

    public function test_command_with_fix_corrects_stock_and_attributes_to_org_user(): void
    {
        Mail::fake();
        Notification::fake();
        $org = $this->org();
        $actor = User::create([
            'name' => 'Owner', 'email' => 'owner@o.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);
        $batched = Product::create([
            'organization_id' => $org->id, 'sku' => 'B-1', 'name' => 'Batched',
            'price' => 10, 'currency' => 'USD', 'stock' => 5, 'min_stock' => 0,
            'tracking_type' => 'batch', 'is_active' => true,
        ]);
        ProductBatch::create(['organization_id' => $org->id, 'product_id' => $batched->id, 'batch_number' => 'L1', 'quantity' => 8]);

        $this->artisan('inventory:reconcile-tracked-stock --fix')
            ->assertExitCode(0);

        $this->assertSame(8, $batched->fresh()->stock);
        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $batched->id,
            'user_id' => $actor->id,
            'type' => 'recount',
        ]);
    }
}
