<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** On-hand quantity of one variant at one location, independent of the parent product's stock. */
class VariantLocationStock extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'product_variant_id', 'location_id', 'quantity'];

    protected $casts = ['quantity' => 'integer'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class, 'location_id');
    }
}
