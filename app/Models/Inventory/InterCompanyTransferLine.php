<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One product (or variant) moved by an inter-company transfer, with the
 * stock adjustment it booked on each side, the serials or batches it moved
 * for a tracked product, and, for a component of a kit, the kit on each side.
 *
 * @property int $id
 * @property int $inter_company_transfer_id
 * @property int $from_product_id
 * @property int|null $from_product_variant_id
 * @property int|null $from_location_id
 * @property int $to_product_id
 * @property int|null $to_product_variant_id
 * @property int|null $to_location_id
 * @property int $quantity
 * @property int|null $out_adjustment_id
 * @property int|null $in_adjustment_id
 * @property int|null $from_kit_product_id
 * @property int|null $to_kit_product_id
 * @property int|null $kit_quantity
 * @property-read InterCompanyTransfer $transfer
 * @property-read StockAdjustment|null $outAdjustment
 * @property-read StockAdjustment|null $inAdjustment
 * @property-read Collection<int, InterCompanyTransferSerial> $serials
 * @property-read Collection<int, InterCompanyTransferBatch> $batches
 */
class InterCompanyTransferLine extends Model
{
    protected $fillable = [
        'inter_company_transfer_id',
        'from_product_id',
        'from_product_variant_id',
        'from_location_id',
        'to_product_id',
        'to_product_variant_id',
        'to_location_id',
        'quantity',
        'out_adjustment_id',
        'in_adjustment_id',
        'from_kit_product_id',
        'to_kit_product_id',
        'kit_quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'from_kit_product_id' => 'integer',
        'to_kit_product_id' => 'integer',
        'kit_quantity' => 'integer',
    ];

    /**
     * @return BelongsTo<InterCompanyTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InterCompanyTransfer::class, 'inter_company_transfer_id');
    }

    /**
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function outAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'out_adjustment_id');
    }

    /**
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function inAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'in_adjustment_id');
    }

    /**
     * @return HasMany<InterCompanyTransferSerial, $this>
     */
    public function serials(): HasMany
    {
        return $this->hasMany(InterCompanyTransferSerial::class);
    }

    /**
     * @return HasMany<InterCompanyTransferBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(InterCompanyTransferBatch::class);
    }
}
