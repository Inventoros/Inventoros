<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\SequenceNumber;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a stock transfer between two locations.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $transfer_number
 * @property int $from_location_id
 * @property int $to_location_id
 * @property int $transferred_by
 * @property string $status
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Auth\Organization $organization
 * @property-read \App\Models\Inventory\ProductLocation $fromLocation
 * @property-read \App\Models\Inventory\ProductLocation $toLocation
 * @property-read \App\Models\User $transferredBy
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Inventory\StockTransferItem[] $items
 */
class StockTransfer extends Model
{
    use LogsActivity;

    protected $fillable = [
        'organization_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'transfer_number',
        'from_location_id',
        'to_location_id',
        'transferred_by',
        'status',
        'is_inter_warehouse',
        'shipping_method',
        'tracking_number',
        'shipped_at',
        'estimated_arrival',
        'notes',
        'completed_at',
        'completed_by',
        'approval_status',
        'approval_requested_by',
        'approval_requested_at',
        'approved_by',
        'approved_at',
        'approval_notes',
    ];

    /**
     * Approval states. NULL means no approval was asked for.
     */
    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'is_inter_warehouse' => 'boolean',
            'shipped_at' => 'datetime',
            'estimated_arrival' => 'datetime',
            'completed_at' => 'datetime',
            'approval_requested_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Get the organization that owns the transfer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    /**
     * Get the source location.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\ProductLocation, $this>
     */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class, 'from_location_id');
    }

    /**
     * Get the destination location.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\ProductLocation, $this>
     */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class, 'to_location_id');
    }

    /**
     * Get the user who initiated the transfer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }

    /**
     * Get the user who completed the transfer (null before completion and on
     * transfers completed before this was recorded). Named completer, not
     * completedBy, so it does not serialize over the completed_by column.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Get the user who approved or rejected this transfer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Whether shipping or completing this transfer still waits on approval.
     */
    public function awaitsApproval(): bool
    {
        return in_array($this->approval_status, [self::APPROVAL_PENDING, self::APPROVAL_REJECTED], true);
    }

    /**
     * Get the items in this transfer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Inventory\StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    /**
     * Scope to filter by organization.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope to filter by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Generate a unique transfer number scoped by organization.
     *
     * @param int|null $organizationId
     * @return string
     */
    public static function generateTransferNumber(?int $organizationId = null): string
    {
        return SequenceNumber::next(static::class, 'transfer_number', 'ST-'.now()->format('Ymd').'-', $organizationId);
    }
}
