<?php

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockAdjustment extends Model
{
    protected $fillable = [
        'organization_id',
        'product_id',
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
     * Get the organization that owns the stock adjustment
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the product associated with the adjustment
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the user who made the adjustment
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the reference model (Order, Purchase, etc.)
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to filter by organization
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope to filter by product
     */
    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope to filter by type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Create a stock adjustment and update product stock
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
}
