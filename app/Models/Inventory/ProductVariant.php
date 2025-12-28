<?php

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'organization_id',
        'sku',
        'barcode',
        'title',
        'option_values',
        'price',
        'purchase_price',
        'compare_at_price',
        'stock',
        'min_stock',
        'image',
        'weight',
        'weight_unit',
        'is_active',
        'requires_shipping',
        'position',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'option_values' => 'array',
            'price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'stock' => 'integer',
            'min_stock' => 'integer',
            'weight' => 'decimal:3',
            'is_active' => 'boolean',
            'requires_shipping' => 'boolean',
            'position' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the product that owns this variant.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the organization that owns this variant.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get stock adjustments for this variant.
     */
    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'product_variant_id')->latest();
    }

    /**
     * Get the effective price (variant price or fallback to product price).
     */
    public function getEffectivePriceAttribute(): float
    {
        return $this->price ?? $this->product->price ?? 0;
    }

    /**
     * Get the effective purchase price (variant or fallback to product).
     */
    public function getEffectivePurchasePriceAttribute(): float
    {
        return $this->purchase_price ?? $this->product->purchase_price ?? 0;
    }

    /**
     * Get the profit per unit.
     */
    public function getProfitAttribute(): float
    {
        $price = $this->effective_price;
        $cost = $this->effective_purchase_price;

        if (!$cost || !$price) {
            return 0;
        }
        return $price - $cost;
    }

    /**
     * Get the profit margin percentage.
     */
    public function getProfitMarginAttribute(): float
    {
        $price = $this->effective_price;
        $cost = $this->effective_purchase_price;

        if (!$cost || !$price || $price == 0) {
            return 0;
        }
        return (($price - $cost) / $price) * 100;
    }

    /**
     * Check if the variant is low on stock.
     */
    public function isLowStock(): bool
    {
        return $this->stock <= $this->min_stock;
    }

    /**
     * Check if the variant is out of stock.
     */
    public function isOutOfStock(): bool
    {
        return $this->stock <= 0;
    }

    /**
     * Check if this variant is on sale.
     */
    public function isOnSale(): bool
    {
        return $this->compare_at_price && $this->compare_at_price > $this->effective_price;
    }

    /**
     * Get the discount percentage.
     */
    public function getDiscountPercentageAttribute(): float
    {
        if (!$this->isOnSale() || $this->compare_at_price == 0) {
            return 0;
        }
        return (($this->compare_at_price - $this->effective_price) / $this->compare_at_price) * 100;
    }

    /**
     * Generate title from option values.
     */
    public function generateTitle(): string
    {
        if (empty($this->option_values)) {
            return 'Default';
        }
        return implode(' / ', array_values($this->option_values));
    }

    /**
     * Auto-generate title before saving.
     */
    protected static function booted(): void
    {
        static::saving(function (ProductVariant $variant) {
            if (empty($variant->title)) {
                $variant->title = $variant->generateTitle();
            }
        });
    }

    /**
     * Scope a query to only include variants from a specific organization.
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope a query to only include active variants.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include variants with low stock.
     */
    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock', '<=', 'min_stock');
    }

    /**
     * Scope to order by position.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }

    /**
     * Get a specific option value.
     */
    public function getOptionValue(string $optionName): ?string
    {
        return $this->option_values[$optionName] ?? null;
    }

    /**
     * Check if variant matches given option values.
     */
    public function matchesOptions(array $optionValues): bool
    {
        foreach ($optionValues as $name => $value) {
            if (($this->option_values[$name] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }
}
