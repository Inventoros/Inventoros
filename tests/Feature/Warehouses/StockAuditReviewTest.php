<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Exceptions\InvalidStateException;
use App\Models\ActivityLog;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\Role;
use App\Models\User;
use App\Services\StockAuditReviewService;
use App\Services\StockAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class StockAuditReviewTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    private StockAudit $audit;

    private StockAuditItem $item;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
        $this->audit = $this->makeAudit($this->locationA, 'Review audit');
        $this->audit->update(['status' => 'in_progress', 'require_variance_approval' => true]);
        $this->item = $this->audit->items()->create(['product_id' => $this->productA->id,
            'location_id' => $this->locationA->id, 'system_quantity' => 60, 'status' => 'pending']);
        $this->supervisor = User::factory()->forOrganization($this->organization->id)->create();
        $role = Role::create(['organization_id' => $this->organization->id, 'slug' => 'review-supervisor',
            'name' => 'Supervisor', 'permissions' => ['view_stock_audits', 'approve_stock_adjustments']]);
        $this->supervisor->roles()->attach($role);
        $this->warehouseA->users()->attach($this->supervisor);
    }

    private function saveCount(int $quantity = 55, ?string $revision = null): void
    {
        app(StockAuditService::class)->recordCount($this->audit, $this->item, $this->restricted, $quantity, 'Physical count', $revision);
    }

    private function decide(string $decision = 'approved', ?User $actor = null, ?string $revision = null): void
    {
        app(StockAuditReviewService::class)->decide($this->audit, $this->item, $actor ?? $this->supervisor,
            $decision, $revision ?? $this->item->fresh()->count_revision, $decision === 'recount_requested' ? 'Check the back shelf' : 'Checked');
    }

    private function assertCompletionBlocked(bool $allowUncounted = false): void
    {
        try {
            app(StockAuditService::class)->complete($this->audit, $this->admin, $allowUncounted);
            $this->fail('Expected review gate');
        } catch (InvalidStateException $error) {
            $this->assertSame('unreviewed_variances', $error->errorCode);
        }
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_variance_requires_review_without_booking_stock_on_approval(): void
    {
        $this->saveCount();
        $this->assertCompletionBlocked();
        $this->decide();
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertDatabaseCount('stock_adjustments', 0);
        $this->assertSame(1, app(StockAuditService::class)->complete($this->audit, $this->admin));
        $this->assertSame(95, $this->productA->fresh()->stock);
        $this->assertDatabaseCount('stock_audit_reviews', 1);
    }

    public function test_a_new_count_invalidates_the_previous_approval_even_with_the_same_quantity(): void
    {
        $this->freezeTime();
        $this->saveCount();
        $this->decide();
        $this->saveCount();
        $this->assertCompletionBlocked();
        $this->decide();
        $this->assertDatabaseCount('stock_audit_reviews', 2);
    }

    public function test_recount_preserves_evidence_blocks_bypass_and_invalidates_older_drafts(): void
    {
        $this->saveCount(60);
        $before = $this->item->fresh();
        $this->decide('recount_requested');
        $this->assertSame(60, $this->item->fresh()->counted_quantity);
        $this->assertDatabaseCount('stock_audit_counts', 1);
        $this->assertNotSame($before->count_revision, $this->item->fresh()->count_revision);
        $this->assertCompletionBlocked(true);
        try {
            $this->saveCount(60, $before->count_revision);
            $this->fail('Expected stale draft rejection');
        } catch (InvalidStateException $error) {
            $this->assertSame('stale_count', $error->errorCode);
        }
        $this->saveCount(60, $this->item->fresh()->count_revision);
        $this->assertSame(0, app(StockAuditService::class)->complete($this->audit, $this->admin));
        $this->assertDatabaseCount('stock_audit_counts', 2);
    }

    public function test_allow_uncounted_does_not_bypass_unreviewed_variances(): void
    {
        $this->saveCount();
        $this->audit->items()->create(['product_id' => $this->productB->id, 'system_quantity' => 50, 'status' => 'pending']);
        $this->assertCompletionBlocked(true);
    }

    public function test_old_audits_and_balanced_counts_do_not_gain_a_review_requirement(): void
    {
        $this->audit->update(['require_variance_approval' => false]);
        $this->saveCount();
        $this->assertSame(1, app(StockAuditService::class)->complete($this->audit, $this->admin));
    }

    public function test_review_is_bound_to_the_latest_evidence_and_cannot_be_decided_twice(): void
    {
        $this->saveCount();
        $old = $this->item->fresh()->count_revision;
        $this->saveCount(54);
        try {
            $this->decide(revision: $old);
            $this->fail('Expected stale decision rejection');
        } catch (InvalidStateException $error) {
            $this->assertSame('stale_count', $error->errorCode);
        }
        $this->decide();
        try {
            $this->decide();
            $this->fail('Expected duplicate decision rejection');
        } catch (InvalidStateException $error) {
            $this->assertSame('review_already_decided', $error->errorCode);
        }
        $this->assertDatabaseCount('stock_audit_reviews', 1);
    }

    public function test_changed_evidence_cannot_use_an_old_approval_even_if_sequence_was_not_incremented(): void
    {
        $this->saveCount();
        $this->decide();
        $this->item->update(['counted_quantity' => 40]);
        $this->assertCompletionBlocked();
    }

    public function test_review_requires_both_permissions_tenant_ownership_and_warehouse_access(): void
    {
        $this->saveCount();
        $url = route('stock-audits.items.review', ['stockAudit' => $this->audit->id, 'item' => $this->item->id]);
        $data = ['decision' => 'approved', 'expected_revision' => $this->item->fresh()->count_revision];
        $this->actingAs($this->restricted)->postJson($url, $data)->assertForbidden();
        $foreign = User::factory()->admin()->create();
        $this->actingAs($foreign)->postJson($url, $data)->assertForbidden();
        $this->audit->update(['warehouse_location_id' => $this->locationB->id]);
        $this->actingAs($this->supervisor)->postJson($url, $data)->assertForbidden();
        $this->assertDatabaseCount('stock_audit_reviews', 0);
    }

    public function test_a_supervisor_cannot_approve_their_own_count_but_admins_follow_the_organization_setting(): void
    {
        app(StockAuditService::class)->recordCount($this->audit, $this->item, $this->supervisor, 55);
        try {
            $this->decide();
            $this->fail('Expected self-approval rejection');
        } catch (InvalidStateException $error) {
            $this->assertSame('self_approval', $error->errorCode);
        }
        app(StockAuditService::class)->recordCount($this->audit, $this->item, $this->admin, 55);
        $this->organization->update(['settings' => ['approvals' => ['admins_can_self_approve' => false]]]);
        try {
            $this->decide(actor: $this->admin);
            $this->fail('Expected disabled admin self-approval');
        } catch (InvalidStateException $error) {
            $this->assertSame('self_approval', $error->errorCode);
        }
        $this->organization->update(['settings' => ['approvals' => ['admins_can_self_approve' => true]]]);
        $this->decide(actor: $this->admin);
        $this->assertSame('approved', $this->item->fresh()->review_status);
    }

    public function test_a_token_without_audit_view_ability_cannot_review(): void
    {
        $this->saveCount();
        $url = '/api/v1/stock-audits/'.$this->audit->id.'/items/'.$this->item->id.'/review';
        $data = ['decision' => 'approved', 'expected_revision' => $this->item->fresh()->count_revision];
        $limited = $this->supervisor->createToken('review only', ['approve_stock_adjustments'])->plainTextToken;
        $this->withToken($limited)->postJson($url, $data)->assertForbidden();
        $this->assertDatabaseCount('stock_audit_reviews', 0);
    }

    public function test_a_scoped_review_token_can_decide_and_history_keeps_the_decision(): void
    {
        $this->saveCount();
        $url = '/api/v1/stock-audits/'.$this->audit->id.'/items/'.$this->item->id.'/review';
        $data = ['decision' => 'approved', 'expected_revision' => $this->item->fresh()->count_revision];
        $scoped = $this->supervisor->createToken('review and view', ['view_stock_audits', 'approve_stock_adjustments'])->plainTextToken;
        $response = $this->withToken($scoped)->postJson($url, $data);
        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertJsonPath('data.review_status', 'approved');
        $this->withHeader('Authorization', '')->actingAs($this->admin)->get(route('stock-audits.items.counts', ['stockAudit' => $this->audit->id, 'item' => $this->item->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('reviews.count-1.decision', 'approved'));
    }

    public function test_a_failed_review_log_rolls_back_the_decision_and_summary(): void
    {
        $this->saveCount();
        ActivityLog::creating(static function ($log): void {
            if ($log->action === 'stock_audit_review') {
                throw new \RuntimeException('Audit log unavailable');
            }
        });
        try {
            $this->decide();
            $this->fail('Expected logging failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit log unavailable', $error->getMessage());
        }
        $this->assertSame('pending', $this->item->fresh()->review_status);
        $this->assertDatabaseCount('stock_audit_reviews', 0);
    }

    public function test_review_requirement_is_selected_on_draft_audits_and_cannot_change_after_start(): void
    {
        $this->actingAs($this->admin)->post(route('stock-audits.store'), [
            'name' => 'Optional review', 'audit_type' => 'spot', 'warehouse_location_id' => $this->locationA->id,
            'require_variance_approval' => true, 'product_ids' => [$this->productA->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $draft = StockAudit::latest('id')->firstOrFail();
        $this->assertTrue($draft->require_variance_approval);
        $data = ['name' => $draft->name, 'audit_type' => 'spot', 'warehouse_location_id' => $this->locationA->id];
        $this->put(route('stock-audits.update', $draft), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($draft->fresh()->require_variance_approval);
        app(StockAuditService::class)->start($draft, $this->admin);
        $this->put(route('stock-audits.update', $draft), $data + ['require_variance_approval' => false])->assertRedirect();
        $this->assertTrue($draft->fresh()->require_variance_approval);
    }
}
