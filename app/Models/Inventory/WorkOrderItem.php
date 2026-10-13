<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Represents a component item within a work order.
 *
 * @property int $id
 * @property int $work_order_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $kit_product_id
 * @property string $quantity_required
 * @property string $quantity_consumed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkOrder $workOrder
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read Product|null $kit
 * @property-read Collection|WorkOrderAllocation[] $allocations
 */
class WorkOrderItem extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'work_order_id',
        'product_id',
        'product_variant_id',
        'kit_product_id',
        'quantity_required',
        'quantity_consumed',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_required' => 'decimal:2',
            'quantity_consumed' => 'decimal:2',
        ];
    }

    /**
     * Get the work order this item belongs to.
     *
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Get the component product.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the variant this line draws on, when the component is sold by variant.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Get the virtual kit this line was exploded from, if any.
     *
     * @return BelongsTo<Product, $this>
     */
    public function kit(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'kit_product_id');
    }

    /**
     * Get the serials and batch quantities this line consumed.
     *
     * @return HasMany<WorkOrderAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(WorkOrderAllocation::class);
    }
}
