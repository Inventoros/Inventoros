<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A manual stock adjustment held for approval.
 *
 * Nothing touches stock while the request is pending. On approval the
 * ApprovalService applies it through StockAdjustment::adjust() /
 * adjustVariant(), the same locked, audited path a direct adjustment uses,
 * and links the resulting ledger row here.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $location_id
 * @property int|null $requested_by
 * @property string $type
 * @property int $quantity
 * @property string|null $value
 * @property string|null $reason
 * @property string|null $notes
 * @property string $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $approval_notes
 * @property int|null $stock_adjustment_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read ProductLocation|null $location
 * @property-read User|null $requester
 * @property-read User|null $approver
 * @property-read StockAdjustment|null $stockAdjustment
 */
class StockAdjustmentRequest extends Model
{
    use BelongsToOrganization, LogsActivity;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'organization_id',
        'product_id',
        'product_variant_id',
        'location_id',
        'requested_by',
        'type',
        'quantity',
        'value',
        'reason',
        'notes',
        'status',
        'approved_by',
        'approved_at',
        'approval_notes',
        'stock_adjustment_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'value' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<ProductLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class, 'location_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
