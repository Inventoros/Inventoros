<?php

declare(strict_types=1);

namespace App\Models\Purchasing;

use App\Models\Auth\Organization;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\Supplier;
use App\Models\User;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a purchase order in the system.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $supplier_id
 * @property int|null $created_by
 * @property string $po_number
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $order_date
 * @property \Illuminate\Support\Carbon|null $expected_date
 * @property \Illuminate\Support\Carbon|null $received_date
 * @property string $subtotal
 * @property string $tax
 * @property string $shipping
 * @property string $total
 * @property string|null $currency
 * @property string|null $notes
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read string $status_color
 * @property-read string $status_label
 * @property-read \App\Models\Auth\Organization $organization
 * @property-read \App\Models\Inventory\Supplier $supplier
 * @property-read \App\Models\User|null $creator
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Purchasing\PurchaseOrderItem[] $items
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Inventory\StockAdjustment[] $stockAdjustments
 */
class PurchaseOrder extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'supplier_id',
        'created_by',
        'po_number',
        'status',
        'order_date',
        'expected_date',
        'received_date',
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
     * Get the organization that owns the purchase order.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the supplier for this purchase order.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the user who created this purchase order.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the items for this purchase order.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Purchasing\PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Get the stock adjustments for this purchase order.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<\App\Models\Inventory\StockAdjustment, $this>
     */
    public function stockAdjustments(): MorphMany
    {
        return $this->morphMany(StockAdjustment::class, 'reference');
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
     * Scope to filter by supplier.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param int $supplierId
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeBySupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Scope for search.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param string $search
     * @return \Illuminate\Database\Eloquent\Builder<static>
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
     *
     * @param int $organizationId
     * @return string
     */
    public static function generatePONumber(int $organizationId): string
    {
        $date = now()->format('Ymd');
        $prefix = "PO-{$date}-";

        // Get the highest number for today
        $lastPO = self::where('organization_id', $organizationId)
            ->where('po_number', 'like', "{$prefix}%")
            ->orderBy('po_number', 'desc')
            ->first();

        if ($lastPO) {
            $lastNumber = (int) substr($lastPO->po_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate and update totals from items.
     *
     * @return void
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
     *
     * @return bool
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT]);
    }

    /**
     * Check if the PO can be sent.
     *
     * @return bool
     */
    public function canBeSent(): bool
    {
        return $this->status === self::STATUS_DRAFT && $this->items()->count() > 0;
    }

    /**
     * Check if the PO can receive items.
     *
     * @return bool
     */
    public function canReceiveItems(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_PARTIAL]);
    }

    /**
     * Check if the PO can be cancelled.
     *
     * @return bool
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT]);
    }

    /**
     * Check if all items have been fully received.
     *
     * @return bool
     */
    public function isFullyReceived(): bool
    {
        return $this->items->every(fn ($item) => $item->isFullyReceived());
    }

    /**
     * Check if any items have been partially received.
     *
     * @return bool
     */
    public function isPartiallyReceived(): bool
    {
        return $this->items->some(fn ($item) => $item->quantity_received > 0)
            && !$this->isFullyReceived();
    }

    /**
     * Update status based on receiving state.
     *
     * @return void
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
     *
     * @return void
     */
    public function markAsSent(): void
    {
        $this->status = self::STATUS_SENT;
        $this->save();
    }

    /**
     * Cancel the purchase order.
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->save();
    }

    /**
     * Get status badge color.
     *
     * @return string
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
     *
     * @return string
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
