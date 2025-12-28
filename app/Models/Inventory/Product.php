<?php

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'sku',
        'name',
        'description',
        'price',
        'selling_price',
        'currency',
        'price_in_currencies',
        'purchase_price',
        'stock',
        'min_stock',
        'max_stock',
        'barcode',
        'notes',
        'image',
        'images',
        'thumbnail',
        'category_id',
        'location_id',
        'is_active',
        'has_variants',
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
            'price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'stock' => 'integer',
            'min_stock' => 'integer',
            'max_stock' => 'integer',
            'is_active' => 'boolean',
            'has_variants' => 'boolean',
            'metadata' => 'array',
            'price_in_currencies' => 'array',
            'images' => 'array',
        ];
    }

    /**
     * Get the organization that owns the product.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the category of the product.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * Get the location of the product.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class, 'location_id');
    }

    /**
     * Get all stock adjustments for this product
     */
    public function stockAdjustments()
    {
        return $this->hasMany(StockAdjustment::class)->latest();
    }

    /**
     * Get the suppliers for this product.
     */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_supplier')
            ->withPivot(['cost_price', 'supplier_sku', 'lead_time_days', 'minimum_order_quantity', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * Get the primary supplier for this product.
     */
    public function primarySupplier()
    {
        return $this->suppliers()->wherePivot('is_primary', true)->first();
    }

    /**
     * Get the options for this product.
     */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->ordered();
    }

    /**
     * Get the variants for this product.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->ordered();
    }

    /**
     * Get active variants for this product.
     */
    public function activeVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->active()->ordered();
    }

    /**
     * Get the total stock across all variants (or product stock if no variants).
     */
    public function getTotalStockAttribute(): int
    {
        if ($this->has_variants && $this->variants()->exists()) {
            return $this->variants()->sum('stock');
        }
        return $this->stock;
    }

    /**
     * Get the price range for products with variants.
     */
    public function getPriceRangeAttribute(): array
    {
        if (!$this->has_variants || !$this->variants()->exists()) {
            return ['min' => $this->price, 'max' => $this->price];
        }

        $variants = $this->variants()->whereNotNull('price')->get();
        if ($variants->isEmpty()) {
            return ['min' => $this->price, 'max' => $this->price];
        }

        return [
            'min' => $variants->min('price') ?? $this->price,
            'max' => $variants->max('price') ?? $this->price,
        ];
    }

    /**
     * Find a variant by its option values.
     */
    public function findVariant(array $optionValues): ?ProductVariant
    {
        return $this->variants()->get()->first(fn($v) => $v->matchesOptions($optionValues));
    }

    /**
     * Generate all possible variant combinations from options.
     */
    public function generateVariantCombinations(): array
    {
        $options = $this->options()->ordered()->get();

        if ($options->isEmpty()) {
            return [];
        }

        $combinations = [[]];

        foreach ($options as $option) {
            $newCombinations = [];
            foreach ($combinations as $combination) {
                foreach ($option->values as $value) {
                    $newCombinations[] = array_merge($combination, [$option->name => $value]);
                }
            }
            $combinations = $newCombinations;
        }

        return $combinations;
    }

    /**
     * Scope a query to only include products from a specific organization.
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope a query to only include active products.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include products with low stock.
     */
    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock', '<=', 'min_stock');
    }

    /**
     * Check if the product is low on stock.
     */
    public function isLowStock(): bool
    {
        return $this->stock <= $this->min_stock;
    }

    /**
     * Check if the product is out of stock.
     */
    public function isOutOfStock(): bool
    {
        return $this->stock <= 0;
    }

    /**
     * Get the profit per unit.
     */
    public function getProfitAttribute(): float
    {
        if (!$this->purchase_price || !$this->price) {
            return 0;
        }
        return $this->price - $this->purchase_price;
    }

    /**
     * Get the profit margin percentage.
     */
    public function getProfitMarginAttribute(): float
    {
        if (!$this->purchase_price || !$this->price || $this->price == 0) {
            return 0;
        }
        return (($this->price - $this->purchase_price) / $this->price) * 100;
    }

    /**
     * Get the total profit for all stock.
     */
    public function getTotalProfitAttribute(): float
    {
        return $this->profit * $this->stock;
    }
}
