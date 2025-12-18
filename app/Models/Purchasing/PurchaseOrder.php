<?php

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
     * Get the organization that owns the purchase order
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the supplier for this purchase order
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the user who created this purchase order
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the items for this purchase order
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Get the stock adjustments for this purchase order
     */
    public function stockAdjustments(): MorphMany
    {
        return $this->morphMany(StockAdjustment::class, 'reference');
    }

    /**
     * Scope to filter by organization
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by supplier
     */
    public function scopeBySupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Scope for search
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
     * Generate a unique PO number
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
     * Calculate and update totals from items
     */
    public function calculateTotals(): void
    {
        $this->subtotal = $this->items->sum('subtotal');
        $this->tax = $this->items->sum('tax');
        $this->total = $this->subtotal + $this->tax + ($this->shipping ?? 0);
        $this->save();
    }

    /**
     * Check if the PO can be edited
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT]);
    }

    /**
     * Check if the PO can be sent
     */
    public function canBeSent(): bool
    {
        return $this->status === self::STATUS_DRAFT && $this->items()->count() > 0;
    }

    /**
     * Check if the PO can receive items
     */
    public function canReceiveItems(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_PARTIAL]);
    }

    /**
     * Check if the PO can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT]);
    }

    /**
     * Check if all items have been fully received
     */
    public function isFullyReceived(): bool
    {
        return $this->items->every(fn ($item) => $item->isFullyReceived());
    }

    /**
     * Check if any items have been partially received
     */
    public function isPartiallyReceived(): bool
    {
        return $this->items->some(fn ($item) => $item->quantity_received > 0)
            && !$this->isFullyReceived();
    }

    /**
     * Update status based on receiving state
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
     * Mark as sent
     */
    public function markAsSent(): void
    {
        $this->status = self::STATUS_SENT;
        $this->save();
    }

    /**
     * Cancel the purchase order
     */
    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->save();
    }

    /**
     * Get status badge color
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
     * Get status label
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
