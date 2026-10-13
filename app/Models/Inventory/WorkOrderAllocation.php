<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A serial or a batch quantity a work order component line consumed when the
 * work order completed. Lets the ledger say exactly which units went into
 * production, and lets a reversal return exactly those units.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $work_order_id
 * @property int $work_order_item_id
 * @property int $product_id
 * @property int|null $product_serial_id
 * @property int|null $product_batch_id
 * @property int $quantity
 * @property-read ProductSerial|null $serial
 * @property-read ProductBatch|null $batch
 */
class WorkOrderAllocation extends Model
{
    use BelongsToOrganization;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'work_order_id',
        'work_order_item_id',
        'product_id',
        'product_serial_id',
        'product_batch_id',
        'quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return BelongsTo<WorkOrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class, 'work_order_item_id');
    }

    /**
     * @return BelongsTo<ProductSerial, $this>
     */
    public function serial(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }

    /**
     * @return BelongsTo<ProductBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }
}
