<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Models\Auth\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a return/exchange order (RMA).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $order_id
 * @property string $return_number
 * @property string $type
 * @property string $status
 * @property string $reason
 * @property string|null $notes
 * @property string $refund_amount
 * @property int|null $processed_by
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Auth\Organization $organization
 * @property-read \App\Models\Order\Order $order
 * @property-read \App\Models\User|null $processor
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Order\ReturnOrderItem[] $items
 */
class ReturnOrder extends Model
{
    protected $fillable = [
        'organization_id',
        'order_id',
        'return_number',
        'type',
        'status',
        'reason',
        'notes',
        'refund_amount',
        'processed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'refund_amount' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the organization that owns this return order.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the original order.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Order\Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the user who processed this return.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Get the items for this return order.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Order\ReturnOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnOrderItem::class);
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
     * Scope to filter by type.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Generate a unique return number scoped by organization.
     *
     * @param int|null $organizationId
     * @return string
     */
    public static function generateReturnNumber(?int $organizationId = null): string
    {
        $prefix = 'RMA-';
        $date = now()->format('Ymd');

        $query = static::where('return_number', 'like', $prefix . $date . '%');
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }
        $lastReturn = $query->orderBy('return_number', 'desc')->first();

        if ($lastReturn) {
            $lastNumber = (int) substr($lastReturn->return_number, -4);
            $newNumber = str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix . $date . '-' . $newNumber;
    }
}
