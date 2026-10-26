<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Exceptions\InvalidStateException;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditCount;
use App\Models\Inventory\StockAuditItem;
use App\Models\User;
use App\Services\StockAuditService;
use App\Services\WarehouseAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class StockAuditCountHistoryTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    private StockAudit $audit;

    private StockAuditItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
        $this->audit = $this->makeAudit($this->locationA, 'History audit');
        $this->audit->update(['status' => 'in_progress']);
        $this->item = $this->audit->items()->create(['product_id' => $this->productA->id,
            'location_id' => $this->locationA->id, 'system_quantity' => 60, 'status' => 'pending']);
    }

    private function saveCount(int $quantity, ?string $notes = null, ?string $revision = null): StockAuditItem
    {
        return app(StockAuditService::class)->recordCount($this->audit, $this->item, $this->admin, $quantity, $notes, $revision);
    }

    private function historyUrl(?StockAudit $audit = null, ?StockAuditItem $item = null): string
    {
        return route('stock-audits.items.counts', ['stockAudit' => ($audit ?? $this->audit)->id, 'item' => ($item ?? $this->item)->id]);
    }

    public function test_recounts_append_evidence_and_retain_the_author_name_notes_and_snapshot(): void
    {
        $this->saveCount(55, 'First pass');
        $this->saveCount(50, 'Second pass');
        $counts = StockAuditCount::orderBy('sequence')->get();
        $this->assertCount(2, $counts);
        $this->assertSame([1, 2], $counts->pluck('sequence')->all());
        $this->assertSame([55, 50], $counts->pluck('counted_quantity')->all());
        $this->assertSame([-5, -10], $counts->pluck('discrepancy')->all());
        $this->assertSame(['First pass', 'Second pass'], $counts->pluck('notes')->all());
        foreach ($counts as $count) {
            $this->assertSame($this->organization->id, $count->organization_id);
            $this->assertSame($this->audit->id, $count->stock_audit_id);
            $this->assertSame($this->item->id, $count->stock_audit_item_id);
            $this->assertSame($this->admin->id, $count->counted_by);
            $this->assertSame($this->admin->name, $count->actor_name);
            $this->assertSame(60, $count->system_quantity);
            $this->assertFalse($count->is_snapshot);
        }
        $this->assertSame(100, $this->productA->fresh()->stock);
    }

    public function test_identical_recounts_in_the_same_second_have_distinct_revisions_and_reject_stale_writes(): void
    {
        $this->freezeTime();
        $first = $this->saveCount(55, 'Counted twice')->count_revision;
        $second = $this->saveCount(55, 'Counted twice', $first)->count_revision;
        $this->assertNotSame($first, $second);
        try {
            $this->saveCount(58, null, $first);
            $this->fail('Expected stale count rejection');
        } catch (InvalidStateException $error) {
            $this->assertSame('stale_count', $error->errorCode);
        }
        $this->assertDatabaseCount('stock_audit_counts', 2);
        $this->assertSame(55, $this->item->fresh()->counted_quantity);
    }

    public function test_history_failure_rolls_back_the_accepted_count_too(): void
    {
        StockAuditCount::creating(static fn () => throw new \RuntimeException('History storage unavailable'));
        try {
            $this->saveCount(55, 'Must not survive');
            $this->fail('Expected history failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('History storage unavailable', $error->getMessage());
        }
        $this->assertNull($this->item->fresh()->counted_quantity);
        $this->assertSame(0, $this->item->fresh()->count_sequence);
        $this->assertDatabaseCount('stock_audit_counts', 0);
    }

    public function test_closed_audits_cannot_append_history(): void
    {
        $this->audit->update(['status' => 'completed']);
        try {
            $this->saveCount(55);
            $this->fail('Expected closed audit rejection');
        } catch (InvalidStateException $error) {
            $this->assertSame('invalid_status', $error->errorCode);
        }
        $this->assertDatabaseCount('stock_audit_counts', 0);
    }

    public function test_history_is_paginated_and_visible_to_audit_viewers(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->saveCount($i, 'Pass '.$i);
        }
        $this->actingAs($this->restricted)->get($this->historyUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('StockAudits/CountHistory')->where('counts.total', 25)->has('counts.data', 20)
            ->where('counts.data.0.sequence', 25)->where('counts.data.19.sequence', 6));
        $this->get($this->historyUrl().'?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('counts.data', 5)->where('counts.data.0.sequence', 5));
    }

    public function test_history_is_scoped_to_the_audit_organization_warehouse_and_item(): void
    {
        $this->saveCount(55);
        $foreign = User::factory()->admin()->create();
        $this->actingAs($foreign)->get($this->historyUrl())->assertForbidden();
        $other = $this->makeAudit($this->locationA, 'Another audit');
        $this->actingAs($this->admin)->get($this->historyUrl($other))->assertNotFound();
        $this->audit->update(['warehouse_location_id' => $this->locationB->id]);
        $this->actingAs($this->restricted)->get($this->historyUrl())->assertForbidden();
        app(WarehouseAccessService::class)->setOrganizationRestrictsToAssigned($this->organization->id, true);
        $this->actingAs($this->unassigned)->get($this->historyUrl())->assertForbidden();
    }

    public function test_old_counts_are_preserved_once_as_labelled_snapshots_without_inventing_earlier_attempts(): void
    {
        $this->item->update(['counted_quantity' => 0, 'discrepancy' => -60, 'counted_by' => $this->admin->id,
            'status' => 'counted', 'notes' => 'Existing count', 'counted_at' => null]);
        $revision = $this->item->fresh()->count_revision;
        $migration = require database_path('migrations/2026_10_26_000002_create_stock_audit_counts.php');
        $snapshot = new \ReflectionMethod($migration, 'snapshotExistingCounts');
        $snapshot->invoke($migration);
        $snapshot->invoke($migration);
        $this->assertDatabaseCount('stock_audit_counts', 1);
        $this->assertDatabaseHas('stock_audit_counts', ['sequence' => 0, 'counted_quantity' => 0,
            'is_snapshot' => true, 'notes' => 'Existing count', 'actor_name' => $this->admin->name]);
        $this->assertSame($revision, $this->item->fresh()->count_revision);
        $this->saveCount(2, 'New recount', $revision);
        $this->assertDatabaseHas('stock_audit_counts', ['sequence' => 1, 'counted_quantity' => 2, 'is_snapshot' => false]);
    }

    public function test_saved_evidence_cannot_be_changed_or_deleted_through_the_model(): void
    {
        $this->saveCount(55);
        $record = StockAuditCount::firstOrFail();
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $record->update(['counted_quantity' => 99]) : $record->delete();
                $this->fail('Expected immutable history');
            } catch (\LogicException) {
                $this->assertSame(55, $record->fresh()->counted_quantity);
            }
        }
        $this->assertDatabaseCount('stock_audit_counts', 1);
    }

    public function test_completion_books_only_the_latest_count_and_retains_all_accepted_counts(): void
    {
        $this->saveCount(60, 'First count');
        $this->saveCount(40, 'Recount');
        $this->assertSame(1, app(StockAuditService::class)->complete($this->audit, $this->admin));
        $this->assertSame(80, $this->productA->fresh()->stock);
        $this->assertDatabaseCount('stock_audit_counts', 2);
        $this->assertDatabaseCount('stock_adjustments', 1);
    }
}
