<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Represents a stock adjustment record for a product or variant.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $user_id
 * @property string $type
 * @property int $quantity_before
 * @property int $quantity_after
 * @property int $adjustment_quantity
 * @property string|null $reason
 * @property string|null $notes
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Auth\Organization $organization
 * @property-read \App\Models\Inventory\Product $product
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Inventory\ProductVariant|null $variant
 * @property-read \Illuminate\Database\Eloquent\Model|null $reference
 */
class StockAdjustment extends Model
{
    protected $fillable = [
        'organization_id',
        'product_id',
        'product_variant_id',
        'user_id',
        'type',
        'quantity_before',
        'quantity_after',
        'adjustment_quantity',
        'reason',
        'notes',
        'reference_type',
        'reference_id',
    ];

    protected $casts = [
        'quantity_before' => 'integer',
        'quantity_after' => 'integer',
        'adjustment_quantity' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the organization that owns the stock adjustment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the product associated with the adjustment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the user who made the adjustment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the variant associated with the adjustment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Get the reference model (Order, Purchase, etc.).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to filter by variant.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param int $variantId
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForVariant($query, $variantId)
    {
        return $query->where('product_variant_id', $variantId);
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
     * Scope to filter by product.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param int $productId
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope to filter by type.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Create a stock adjustment and update product stock.
     *
     * @param \App\Models\Inventory\Product $product
     * @param int $quantity
     * @param string $type
     * @param string|null $reason
     * @param string|null $notes
     * @param \Illuminate\Database\Eloquent\Model|null $reference
     * @return static
     */
    public static function adjust(
        Product $product,
        int $quantity,
        string $type,
        ?string $reason = null,
        ?string $notes = null,
        ?Model $reference = null
    ): self {
        $quantityBefore = $product->stock;
        $quantityAfter = $quantityBefore + $quantity;

        // Create the adjustment record
        $adjustment = self::create([
            'organization_id' => $product->organization_id,
            'product_id' => $product->id,
            'user_id' => auth()->id(),
            'type' => $type,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'adjustment_quantity' => $quantity,
            'reason' => $reason,
            'notes' => $notes,
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id' => $reference?->id,
        ]);

        // Update the product stock
        $product->update(['stock' => $quantityAfter]);

        return $adjustment;
    }

    /**
     * Create a stock adjustment for a product variant.
     *
     * @param \App\Models\Inventory\ProductVariant $variant
     * @param int $quantity
     * @param string $type
     * @param string|null $reason
     * @param string|null $notes
     * @param \Illuminate\Database\Eloquent\Model|null $reference
     * @return static
     */
    public static function adjustVariant(
        ProductVariant $variant,
        int $quantity,
        string $type,
        ?string $reason = null,
        ?string $notes = null,
        ?Model $reference = null
    ): self {
        $quantityBefore = $variant->stock;
        $quantityAfter = $quantityBefore + $quantity;

        // Create the adjustment record
        $adjustment = self::create([
            'organization_id' => $variant->organization_id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'user_id' => auth()->id(),
            'type' => $type,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'adjustment_quantity' => $quantity,
            'reason' => $reason,
            'notes' => $notes,
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id' => $reference?->id,
        ]);

        // Update the variant stock
        $variant->update(['stock' => $quantityAfter]);

        return $adjustment;
    }
}
