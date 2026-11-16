<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Exceptions\InvalidStateException;
use App\Models\Auth\Organization;
use App\Models\Inventory\StockAudit;
use App\Models\Role;
use App\Models\User;
use App\Services\StockAuditReviewService;
use App\Services\StockAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * When an organization turns on stock-adjustment approvals, a stock audit
 * must not book its variances without a reviewer, whatever the creator
 * chose for require_variance_approval.
 */
final class StockAuditApprovalPolicyTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
        $this->supervisor = User::factory()->forOrganization($this->organization->id)->create();
        $role = Role::create(['organization_id' => $this->organization->id, 'slug' => 'audit-approver',
            'name' => 'Approver', 'permissions' => ['view_stock_audits', 'approve_stock_adjustments']]);
        $this->supervisor->roles()->attach($role);
        $this->warehouseA->users()->attach($this->supervisor);
    }

    private function enableAdjustmentApprovals(?Organization $organization = null): void
    {
        ($organization ?? $this->organization)->update(['settings' => ['approvals' => ['stock_adjustments_enabled' => true]]]);
    }

    private function storeAudit(bool $requireReview): StockAudit
    {
        $this->actingAs($this->admin)->post(route('stock-audits.store'), [
            'name' => 'Opt-out audit', 'audit_type' => 'spot', 'warehouse_location_id' => $this->locationA->id,
            'require_variance_approval' => $requireReview, 'product_ids' => [$this->productA->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return StockAudit::latest('id')->firstOrFail();
    }

    public function test_creating_an_audit_cannot_opt_out_of_review_when_adjustment_approvals_are_on(): void
    {
        $this->enableAdjustmentApprovals();

        $this->assertTrue($this->storeAudit(false)->require_variance_approval);
    }

    public function test_the_api_cannot_opt_out_of_review_when_adjustment_approvals_are_on(): void
    {
        $this->enableAdjustmentApprovals();
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/stock-audits', [
            'name' => 'API opt-out', 'audit_type' => 'spot', 'warehouse_location_id' => $this->locationA->id,
            'require_variance_approval' => false, 'product_ids' => [$this->productA->id],
        ])->assertCreated()->assertJsonPath('data.require_variance_approval', true);
    }

    public function test_editing_a_draft_cannot_turn_review_off_when_adjustment_approvals_are_on(): void
    {
        $this->enableAdjustmentApprovals();
        $draft = $this->storeAudit(true);

        $this->put(route('stock-audits.update', $draft), [
            'name' => $draft->name, 'audit_type' => 'spot', 'warehouse_location_id' => $this->locationA->id,
            'require_variance_approval' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($draft->fresh()->require_variance_approval);
    }

    public function test_a_draft_created_before_approvals_were_turned_on_requires_review_once_started(): void
    {
        $draft = $this->storeAudit(false);
        $this->assertFalse($draft->require_variance_approval);
        $this->enableAdjustmentApprovals();

        app(StockAuditService::class)->start($draft, $this->admin);

        $this->assertTrue($draft->fresh()->require_variance_approval);
    }

    public function test_an_in_progress_audit_without_review_cannot_book_variances_once_approvals_are_on(): void
    {
        $audit = $this->storeAudit(false);
        app(StockAuditService::class)->start($audit, $this->admin);
        $item = $audit->items()->firstOrFail();
        app(StockAuditService::class)->recordCount($audit, $item, $this->restricted, 55);
        $this->enableAdjustmentApprovals();

        try {
            app(StockAuditService::class)->complete($audit->fresh(), $this->restricted);
            $this->fail('Expected the review gate');
        } catch (InvalidStateException $error) {
            $this->assertSame('unreviewed_variances', $error->errorCode);
        }
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertDatabaseCount('stock_adjustments', 0);

        // A user who holds the approve permission reviews it; then it books.
        app(StockAuditReviewService::class)->decide($audit->fresh(), $item, $this->supervisor, 'approved', $item->fresh()->count_revision, 'Checked');
        $this->assertSame(1, app(StockAuditService::class)->complete($audit->fresh(), $this->restricted));
        $this->assertSame(95, $this->productA->fresh()->stock);
    }

    public function test_the_counter_still_cannot_approve_their_own_variance(): void
    {
        $this->enableAdjustmentApprovals();
        $audit = $this->storeAudit(false);
        app(StockAuditService::class)->start($audit, $this->admin);
        $item = $audit->items()->firstOrFail();
        app(StockAuditService::class)->recordCount($audit, $item, $this->supervisor, 55);

        try {
            app(StockAuditReviewService::class)->decide($audit->fresh(), $item, $this->supervisor, 'approved', $item->fresh()->count_revision, 'Mine');
            $this->fail('Expected self-approval rejection');
        } catch (InvalidStateException $error) {
            $this->assertSame('self_approval', $error->errorCode);
        }

        // A counter without the approve permission is refused outright.
        $this->actingAs($this->restricted)->postJson(
            route('stock-audits.items.review', ['stockAudit' => $audit->id, 'item' => $item->id]),
            ['decision' => 'approved', 'expected_revision' => $item->fresh()->count_revision],
        )->assertForbidden();
        $this->assertDatabaseCount('stock_audit_reviews', 0);
    }

    public function test_without_adjustment_approvals_the_creator_choice_is_kept(): void
    {
        // Another tenant's settings never apply here.
        $other = Organization::create(['name' => 'Other Org', 'email' => 'other@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->enableAdjustmentApprovals($other);

        $audit = $this->storeAudit(false);
        $this->assertFalse($audit->require_variance_approval);
        app(StockAuditService::class)->start($audit, $this->admin);
        $this->assertFalse($audit->fresh()->require_variance_approval);
        $item = $audit->items()->firstOrFail();
        app(StockAuditService::class)->recordCount($audit, $item, $this->restricted, 55);

        $this->assertSame(1, app(StockAuditService::class)->complete($audit->fresh(), $this->restricted));
        $this->assertSame(95, $this->productA->fresh()->stock);
    }
}
