<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InvalidStateException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAudit;
use App\Models\User;
use App\Services\StockAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * INV-SEC-0040: an audit line's variance is measured against the stock on hand
 * when the count is recorded, not the snapshot taken when the audit started,
 * and completion refuses to take stock below zero.
 */
final class StockAuditStaleSnapshotTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private StockAuditService $audits;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Snapshot Co');
        $this->admin = $this->makeAdmin($this->org);
        $this->audits = app(StockAuditService::class);
        $this->actingAs($this->admin);
    }

    private function startAudit(Product $product): StockAudit
    {
        $audit = $this->audits->create($this->org->id, $this->admin, ['name' => 'Count', 'audit_type' => 'spot', 'product_ids' => [$product->id]]);
        $this->audits->start($audit, $this->admin);

        return $audit->fresh();
    }

    private function stock(Product $product): int
    {
        return (int) Product::withoutGlobalScopes()->whereKey($product->id)->value('stock');
    }

    public function test_stock_shipped_before_the_count_is_not_subtracted_twice(): void
    {
        $product = $this->makeProduct($this->org, ['stock' => 10]);
        $audit = $this->startAudit($product);

        // Eight units ship after the snapshot, before the shelf is counted.
        StockAdjustment::adjust(product: $product->fresh(), quantity: -8, type: 'sale', reason: 'Shipped', actor: $this->admin);

        // The counter finds exactly the two that are left.
        $line = $this->audits->recordCount($audit, $audit->items()->firstOrFail(), $this->admin, 2);
        $this->assertSame(2, $line->system_quantity, 'The line is measured against stock when it was counted');
        $this->assertSame(0, $line->discrepancy);

        $this->assertSame(0, $this->audits->complete($audit->fresh(), $this->admin));
        $this->assertSame(2, $this->stock($product));
    }

    public function test_stock_received_after_the_count_stays_booked(): void
    {
        $product = $this->makeProduct($this->org, ['stock' => 10]);
        $audit = $this->startAudit($product);

        // Three are missing from the shelf, then a delivery of five arrives.
        $this->audits->recordCount($audit, $audit->items()->firstOrFail(), $this->admin, 7);
        StockAdjustment::adjust(product: $product->fresh(), quantity: 5, type: 'received', reason: 'Delivery', actor: $this->admin);

        $this->assertSame(1, $this->audits->complete($audit->fresh(), $this->admin));
        $this->assertSame(12, $this->stock($product));
        $this->assertDatabaseHas('stock_adjustments', ['product_id' => $product->id, 'type' => 'recount', 'adjustment_quantity' => -3]);
    }

    public function test_a_real_shortage_found_by_the_count_is_still_booked(): void
    {
        $product = $this->makeProduct($this->org, ['stock' => 10]);
        $audit = $this->startAudit($product);

        $this->audits->recordCount($audit, $audit->items()->firstOrFail(), $this->admin, 6);

        $this->assertSame(1, $this->audits->complete($audit->fresh(), $this->admin));
        $this->assertSame(6, $this->stock($product));
    }

    public function test_completion_refuses_to_take_stock_below_zero_and_a_recount_clears_it(): void
    {
        $product = $this->makeProduct($this->org, ['stock' => 10]);
        $audit = $this->startAudit($product);

        // Counted 2 on a shelf the system thinks holds 10, then 5 more ship.
        $this->audits->recordCount($audit, $audit->items()->firstOrFail(), $this->admin, 2);
        StockAdjustment::adjust(product: $product->fresh(), quantity: -5, type: 'sale', reason: 'Shipped', actor: $this->admin);

        try {
            $this->audits->complete($audit->fresh(), $this->admin);
            $this->fail('Completion should be refused');
        } catch (InvalidStateException $e) {
            $this->assertSame('stock_moved_since_count', $e->errorCode);
        }
        $this->assertSame(5, $this->stock($product), 'A refused completion books nothing');
        $this->assertSame('in_progress', $audit->fresh()->status);

        // Recounting measures against current stock again.
        $this->audits->recordCount($audit->fresh(), $audit->items()->firstOrFail(), $this->admin, 0);
        $this->audits->complete($audit->fresh(), $this->admin);
        $this->assertSame(0, $this->stock($product));
    }
}
