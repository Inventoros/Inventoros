<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class VariantReplenishmentPolicy extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reorder_point' => 'integer', 'target_stock' => 'integer', 'reorder_quantity' => 'integer',
            'minimum_order_quantity' => 'integer', 'unit_cost' => 'decimal:2', 'auto_reorder' => 'boolean'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class)->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by');
    }
}
