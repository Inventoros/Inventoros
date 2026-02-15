<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a specific variant of a product (e.g., red shirt size M).
 *
 * @property int $id
 * @property int $product_id
 * @property int $organization_id
 * @property string|null $sku
 * @property string|null $barcode
 * @property string|null $title
 * @property array|null $option_values
 * @property string|null $price
 * @property string|null $purchase_price
 * @property string|null $compare_at_price
 * @property int $stock
 * @property int|null $min_stock
 * @property string|null $image
 * @property string|null $weight
 * @property string|null $weight_unit
 * @property bool $is_active
 * @property bool $requires_shipping
 * @property int $position
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read float $effective_price
 * @property-read float $effective_purchase_price
 * @property-read float $profit
 * @property-read float $profit_margin
 * @property-read float $discount_percentage
 * @property-read \App\Models\Inventory\Product $product
 * @property-read \App\Models\Auth\Organization $organization
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Inventory\StockAdjustment[] $stockAdjustments
 */
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
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the organization that owns this variant.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get stock adjustments for this variant.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Inventory\StockAdjustment, $this>
     */
    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'product_variant_id')->latest();
    }

    /**
     * Get the effective price (variant price or fallback to product price).
     *
     * @return float
     */
    public function getEffectivePriceAttribute(): float
    {
        return $this->price ?? $this->product->price ?? 0;
    }

    /**
     * Get the effective purchase price (variant or fallback to product).
     *
     * @return float
     */
    public function getEffectivePurchasePriceAttribute(): float
    {
        return $this->purchase_price ?? $this->product->purchase_price ?? 0;
    }

    /**
     * Get the profit per unit.
     *
     * @return float
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
     *
     * @return float
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
     *
     * @return bool
     */
    public function isLowStock(): bool
    {
        return $this->stock <= $this->min_stock;
    }

    /**
     * Check if the variant is out of stock.
     *
     * @return bool
     */
    public function isOutOfStock(): bool
    {
        return $this->stock <= 0;
    }

    /**
     * Check if this variant is on sale.
     *
     * @return bool
     */
    public function isOnSale(): bool
    {
        return $this->compare_at_price && $this->compare_at_price > $this->effective_price;
    }

    /**
     * Get the discount percentage.
     *
     * @return float
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
     *
     * @return string
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
     *
     * @return void
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
     * Scope a query to only include active variants.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include variants with low stock.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock', '<=', 'min_stock');
    }

    /**
     * Scope to order by position.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }

    /**
     * Get a specific option value.
     *
     * @param string $optionName
     * @return string|null
     */
    public function getOptionValue(string $optionName): ?string
    {
        return $this->option_values[$optionName] ?? null;
    }

    /**
     * Check if variant matches given option values.
     *
     * @param array<string, string> $optionValues
     * @return bool
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
