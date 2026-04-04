<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\User;
use App\Models\Warehouse;
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
    ];

    protected function casts(): array
    {
        return [
            'is_inter_warehouse' => 'boolean',
            'shipped_at' => 'datetime',
            'estimated_arrival' => 'datetime',
            'completed_at' => 'datetime',
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
        $prefix = 'ST-';
        $date = now()->format('Ymd');

        $query = static::where('transfer_number', 'like', $prefix . $date . '%');
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }
        $lastTransfer = $query->orderBy('transfer_number', 'desc')->first();

        if ($lastTransfer) {
            $lastNumber = (int) substr($lastTransfer->transfer_number, -4);
            $newNumber = str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix . $date . '-' . $newNumber;
    }
}
