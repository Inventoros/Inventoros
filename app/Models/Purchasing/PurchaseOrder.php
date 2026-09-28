<?php

declare(strict_types=1);

namespace App\Models\Purchasing;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\Supplier;
use App\Models\User;
use App\Support\ApprovalSettings;
use App\Support\SequenceNumber;
use App\Support\SequenceNumberRetry;
use App\Traits\LogsActivity;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Represents a purchase order in the system.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $supplier_id
 * @property int|null $created_by
 * @property string $po_number
 * @property string $status
 * @property Carbon|null $order_date
 * @property Carbon|null $expected_date
 * @property Carbon|null $received_date
 * @property Carbon|null $sent_at
 * @property Carbon|null $queued_at
 * @property string|null $sent_to
 * @property string|null $approval_status
 * @property int|null $approval_requested_by
 * @property Carbon|null $approval_requested_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $approval_notes
 * @property string $subtotal
 * @property string $tax
 * @property string $shipping
 * @property string $total
 * @property string|null $currency
 * @property string|null $notes
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $status_color
 * @property-read string $status_label
 * @property-read Organization $organization
 * @property-read Supplier $supplier
 * @property-read User|null $creator
 * @property-read User|null $approver
 * @property-read User|null $approvalRequester
 * @property-read Collection|PurchaseOrderItem[] $items
 * @property-read Collection|StockAdjustment[] $stockAdjustments
 */
class PurchaseOrder extends Model
{
    use BelongsToOrganization, LogsActivity, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'supplier_id',
        'created_by',
        'po_number',
        'status',
        'order_date',
        'expected_date',
        'received_date',
        'sent_at',
        'sent_to',
        'queued_at',
        'approval_status',
        'approval_requested_by',
        'approval_requested_at',
        'approved_by',
        'approved_at',
        'approval_notes',
        'subtotal',
        'tax',
        'shipping',
        'total',
        'currency',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'received_date' => 'date',
        'sent_at' => 'datetime',
        'queued_at' => 'datetime',
        'approval_requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'shipping' => 'decimal:2',
        'total' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Approval states. NULL means no approval was asked for.
     */
    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    /**
     * Fields that change what was approved. Editing any of them on an
     * approved draft sends it back for approval.
     *
     * @var array<int, string>
     */
    public const APPROVED_TERMS = [
        'supplier_id', 'subtotal', 'tax', 'shipping', 'total', 'currency',
    ];

    protected static function booted(): void
    {
        static::updating(function (PurchaseOrder $po): void {
            if ($po->status === self::STATUS_DRAFT
                && $po->getOriginal('approval_status') === self::APPROVAL_APPROVED
                && ! $po->isDirty('approval_status')
                && $po->isDirty(self::APPROVED_TERMS)) {
                $po->clearApproval();
            }
        });
    }

    /**
     * Drop a previous approval decision so the PO has to be approved again.
     */
    public function clearApproval(): void
    {
        $this->approval_status = null;
        $this->approved_by = null;
        $this->approved_at = null;
    }

    /**
     * Get the organization that owns the purchase order.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the supplier for this purchase order.
     *
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the user who created this purchase order.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who approved or rejected this purchase order.
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who submitted this purchase order for approval.
     *
     * @return BelongsTo<User, $this>
     */
    public function approvalRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approval_requested_by');
    }

    /**
     * Get the items for this purchase order.
     *
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Get the stock adjustments for this purchase order.
     *
     * @return MorphMany<StockAdjustment, $this>
     */
    public function stockAdjustments(): MorphMany
    {
        return $this->morphMany(StockAdjustment::class, 'reference');
    }

    /**
     * Scope to filter by organization.
     *
     * @param  Builder<static>  $query
     * @param  int  $organizationId
     * @return Builder<static>
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope to filter by status.
     *
     * @param  Builder<static>  $query
     * @param  string  $status
     * @return Builder<static>
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by supplier.
     *
     * @param  Builder<static>  $query
     * @param  int  $supplierId
     * @return Builder<static>
     */
    public function scopeBySupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Scope for search.
     *
     * @param  Builder<static>  $query
     * @param  string  $search
     * @return Builder<static>
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('po_number', 'like', "%{$search}%")
                ->orWhereHas('supplier', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%");
                });
        });
    }

    /**
     * Generate a unique PO number.
     */
    public static function generatePONumber(int $organizationId): string
    {
        return SequenceNumber::next(static::class, 'po_number', 'PO-'.now()->format('Ymd').'-', $organizationId);
    }

    /**
     * Create a purchase order (and whatever the callback builds alongside it)
     * with a race-safe PO number.
     *
     * generatePONumber() computes the next number as MAX(po_number) + 1 — a
     * non-atomic read-modify-write. Two concurrent creates in the same tenant
     * on the same day can read the same MAX and land on the same number; the
     * loser then hits the per-org UNIQUE constraint and 500s. Mirroring
     * OrderService, the number generation and the insert run together inside a
     * transaction wrapped in SequenceNumberRetry, so a collision rolls the
     * attempt back and re-runs with a freshly read number.
     *
     * The retry wraps the transaction (not the reverse) so every attempt runs
     * in its own clean transaction — required on Postgres, where a constraint
     * violation aborts the surrounding transaction. Keep side effects that must
     * not repeat (counters, console output, notifications) OUTSIDE this call,
     * acting on the returned order, since the callback re-runs on collision.
     *
     * @param  Closure(string $poNumber): PurchaseOrder  $make  Receives the
     *                                                          generated number and returns the persisted order, creating its
     *                                                          items and totals inside the same transaction.
     */
    public static function createWithNumber(int $organizationId, Closure $make): self
    {
        return SequenceNumberRetry::create(
            fn () => DB::transaction(fn () => $make(self::generatePONumber($organizationId)))
        );
    }

    /**
     * Calculate and update totals from items.
     */
    public function calculateTotals(): void
    {
        $this->subtotal = $this->items->sum('subtotal');
        $this->tax = $this->items->sum('tax');
        $this->total = $this->subtotal + $this->tax + ($this->shipping ?? 0);
        $this->save();
    }

    /**
     * Check if the PO can be edited.
     */
    public function canBeEdited(): bool
    {
        // A draft awaiting an approval decision is frozen so the approver
        // decides on what they see.
        return $this->status === self::STATUS_DRAFT
            && $this->approval_status !== self::APPROVAL_PENDING;
    }

    /**
     * Whether the organization's approval rules apply to this PO's total.
     */
    public function needsApproval(): bool
    {
        $organization = $this->relationLoaded('organization') ? $this->organization : $this->organization_id;

        return ApprovalSettings::forOrganization($organization)
            ->purchaseOrderNeedsApproval((float) $this->total);
    }

    /**
     * A draft that needs approval and does not have it yet.
     */
    public function awaitsApproval(): bool
    {
        return $this->status === self::STATUS_DRAFT
            && $this->approval_status !== self::APPROVAL_APPROVED
            && $this->needsApproval();
    }

    /**
     * Check if the PO can be submitted for approval.
     */
    public function canBeSubmittedForApproval(): bool
    {
        return $this->status === self::STATUS_DRAFT
            && in_array($this->approval_status, [null, self::APPROVAL_REJECTED], true)
            && $this->needsApproval()
            && $this->items()->count() > 0;
    }

    /**
     * Whether the latest email to the supplier is still waiting in the queue:
     * it was queued and has not been delivered since (sent_at is stamped on
     * delivery, so a resend reads as queued until it goes out).
     */
    public function emailIsQueued(): bool
    {
        return $this->queued_at !== null
            && ($this->sent_at === null || $this->sent_at->lessThan($this->queued_at));
    }

    /**
     * Check if the PO can be sent.
     */
    public function canBeSent(): bool
    {
        // A draft with items is sent for the first time; a PO already with the
        // supplier (sent, or partly received) may be re-sent.
        if (in_array($this->status, [self::STATUS_SENT, self::STATUS_PARTIAL], true)) {
            return true;
        }

        return $this->status === self::STATUS_DRAFT
            && $this->items()->count() > 0
            && ! $this->awaitsApproval();
    }

    /**
     * Check if the PO can receive items.
     */
    public function canReceiveItems(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_PARTIAL]);
    }

    /**
     * Check if the PO can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT]);
    }

    /**
     * Check if all items have been fully received.
     */
    public function isFullyReceived(): bool
    {
        return $this->items->every(fn ($item) => $item->isFullyReceived());
    }

    /**
     * Check if any items have been partially received.
     */
    public function isPartiallyReceived(): bool
    {
        return $this->items->some(fn ($item) => $item->quantity_received > 0)
            && ! $this->isFullyReceived();
    }

    /**
     * Update status based on receiving state.
     */
    public function updateReceivingStatus(): void
    {
        if ($this->isFullyReceived()) {
            $this->status = self::STATUS_RECEIVED;
            $this->received_date = now();
        } elseif ($this->isPartiallyReceived()) {
            $this->status = self::STATUS_PARTIAL;
        }
        $this->save();
    }

    /**
     * Mark as sent.
     */
    public function markAsSent(): void
    {
        $this->status = self::STATUS_SENT;
        $this->save();
    }

    /**
     * Cancel the purchase order.
     */
    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->save();
    }

    /**
     * Get status badge color.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'gray',
            self::STATUS_SENT => 'blue',
            self::STATUS_PARTIAL => 'yellow',
            self::STATUS_RECEIVED => 'green',
            self::STATUS_CANCELLED => 'red',
            default => 'gray',
        };
    }

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SENT => 'Sent',
            self::STATUS_PARTIAL => 'Partial',
            self::STATUS_RECEIVED => 'Received',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst($this->status),
        };
    }
}
