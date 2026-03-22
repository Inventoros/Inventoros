<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents an individual item line in a stock audit.
 *
 * @property int $id
 * @property int $stock_audit_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $location_id
 * @property int $system_quantity
 * @property int|null $counted_quantity
 * @property int $discrepancy
 * @property string $status
 * @property int|null $counted_by
 * @property \Illuminate\Support\Carbon|null $counted_at
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Inventory\StockAudit $stockAudit
 * @property-read \App\Models\Inventory\Product $product
 * @property-read \App\Models\Inventory\ProductVariant|null $variant
 * @property-read \App\Models\Inventory\ProductLocation|null $location
 * @property-read \App\Models\User|null $countedByUser
 */
class StockAuditItem extends Model
{
    protected $fillable = [
        'stock_audit_id',
        'product_id',
        'product_variant_id',
        'location_id',
        'system_quantity',
        'counted_quantity',
        'discrepancy',
        'status',
        'counted_by',
        'counted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'integer',
            'counted_quantity' => 'integer',
            'discrepancy' => 'integer',
            'counted_at' => 'datetime',
        ];
    }

    /**
     * Get the stock audit that owns this item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\StockAudit, $this>
     */
    public function stockAudit(): BelongsTo
    {
        return $this->belongsTo(StockAudit::class);
    }

    /**
     * Get the product for this audit item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the variant for this audit item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Get the location for this audit item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\ProductLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class, 'location_id');
    }

    /**
     * Get the user who counted this item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function countedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
