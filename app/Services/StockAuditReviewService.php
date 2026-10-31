<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\ActivityLog;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\Inventory\StockAuditReview;
use App\Models\User;
use App\Support\ApprovalSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockAuditReviewService
{
    public function __construct(private readonly WarehouseAccessService $warehouseAccess) {}

    public function decide(StockAudit $audit, StockAuditItem $item, User $actor, string $decision, string $expectedRevision, ?string $notes = null): StockAuditItem
    {
        abort_unless((int) $audit->organization_id === (int) $actor->organization_id
            && $actor->hasPermission('view_stock_audits') && $actor->hasPermission('approve_stock_adjustments'), 403);
        $this->warehouseAccess->authorizeStockAudit($actor, $audit);
        if (! in_array($decision, ['approved', 'recount_requested'], true) || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedRevision)) {
            throw ValidationException::withMessages(['review' => 'Choose a valid decision and the latest count revision.']);
        }
        if (mb_strlen($notes ?? '') > 500 || ($decision === 'recount_requested' && mb_strlen(trim($notes ?? '')) < 3)) {
            throw ValidationException::withMessages(['notes' => 'Recounts need a reason of 3 to 500 characters. Review notes must not exceed 500 characters.']);
        }

        return DB::transaction(function () use ($audit, $item, $actor, $decision, $expectedRevision, $notes): StockAuditItem {
            $locked = StockAudit::whereKey($audit->id)->where('organization_id', $actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->warehouseAccess->authorizeStockAudit($actor, $locked);
            $line = StockAuditItem::whereKey($item->id)->where('stock_audit_id', $locked->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'in_progress' || ! $locked->require_variance_approval || $line->counted_quantity === null) {
                throw new InvalidStateException('Only saved counts in an in-progress audit requiring review can be reviewed.', 'invalid_review_state');
            }
            if (! hash_equals($line->count_revision, $expectedRevision)) {
                throw new InvalidStateException('This count changed. Review its latest saved value.', 'stale_count');
            }
            if (StockAuditReview::where('stock_audit_item_id', $line->id)->where('count_sequence', $line->count_sequence)->exists()) {
                throw new InvalidStateException('This count already has a review decision. Save a new count before reviewing it again.', 'review_already_decided');
            }
            if ($decision === 'approved' && (int) $line->counted_by === (int) $actor->id
                && ! ($actor->isAdmin() && ApprovalSettings::forOrganization((int) $locked->organization_id)->adminsCanSelfApprove)) {
                throw new InvalidStateException('Another supervisor must approve your own count.', 'self_approval');
            }

            StockAuditReview::create([
                'organization_id' => $locked->organization_id, 'stock_audit_id' => $locked->id,
                'stock_audit_item_id' => $line->id, 'count_sequence' => $line->count_sequence,
                'count_revision' => $line->count_revision, 'decision' => $decision, 'reviewed_by' => $actor->id,
                'reviewer_name' => $actor->name, 'notes' => $notes, 'created_at' => now(),
            ]);
            $line->forceFill([
                'review_status' => $decision, 'review_revision' => $decision === 'approved' ? $line->count_revision : null,
                'recount_generation' => (int) $line->recount_generation + ($decision === 'recount_requested' ? 1 : 0),
                'reviewed_by' => $actor->id, 'reviewer_name' => $actor->name, 'reviewed_at' => now(), 'review_notes' => $notes,
            ])->save();
            ActivityLog::create([
                'organization_id' => $locked->organization_id, 'user_id' => $actor->id, 'category' => ActivityLog::CATEGORY_AUDIT,
                'subject_type' => $line->getMorphClass(), 'subject_id' => $line->id, 'action' => 'stock_audit_review',
                'description' => 'Stock audit '.$locked->audit_number.': '.$decision,
                'properties' => ['old' => [], 'new' => ['decision' => $decision, 'count_sequence' => $line->count_sequence, 'notes' => $notes]],
            ]);
            $item->setRawAttributes($line->getAttributes(), true);

            return $item;
        }, 3);
    }

    public static function needsReview(StockAuditItem $item): bool
    {
        return $item->review_status === 'recount_requested'
            || ($item->counted_quantity !== null && $item->counted_quantity !== $item->system_quantity
                && ($item->review_status !== 'approved' || ! hash_equals($item->count_revision, (string) $item->review_revision)));
    }
}
