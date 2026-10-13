<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\SequenceNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Represents a work order for assembly production.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $created_by
 * @property int|null $warehouse_id
 * @property string $work_order_number
 * @property int $quantity
 * @property int $quantity_produced
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Product $product
 * @property-read User $creator
 * @property-read Warehouse|null $warehouse
 * @property-read Collection|WorkOrderItem[] $items
 * @property-read ProductVariant|null $variant
 * @property-read Collection|WorkOrderOutput[] $outputs
 */
class WorkOrder extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'product_id',
        'product_variant_id',
        'created_by',
        'warehouse_id',
        'work_order_number',
        'quantity',
        'quantity_produced',
        'status',
        'started_at',
        'completed_at',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_produced' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the organization that owns the work order.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the assembly product being produced.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the variant being produced, when the assembly is sold by variant.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Get what the work order produced (variant, serials or batch).
     *
     * @return HasMany<WorkOrderOutput, $this>
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(WorkOrderOutput::class);
    }

    /**
     * Get the serials and batch quantities the work order consumed.
     *
     * @return HasMany<WorkOrderAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(WorkOrderAllocation::class);
    }

    /**
     * Get the user who created the work order.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the warehouse for this work order.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the work order items (component consumption tracking).
     *
     * @return HasMany<WorkOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }

    /**
     * Scope a query to only include work orders for a specific organization.
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
     * Scope a query to filter by status.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Generate a unique work order number in the format WO-YYYYMMDD-0001.
     */
    public static function generateWorkOrderNumber(int $organizationId): string
    {
        return SequenceNumber::next(static::class, 'work_order_number', 'WO-'.Carbon::today()->format('Ymd').'-', $organizationId);
    }
}
