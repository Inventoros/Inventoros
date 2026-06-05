<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Enums\TrackingType;
use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Inventory\Concerns\ActsAsAssembly;
use App\Models\Inventory\Concerns\CalculatesProductProfit;
use App\Models\Inventory\Concerns\HasProductVariants;
use App\Models\Inventory\Concerns\TracksStockLevels;
use App\Traits\LogsActivity;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Represents a product in the inventory.
 *
 * @property int $id
 * @property int $organization_id
 * @property string|null $sku
 * @property string $name
 * @property string|null $description
 * @property string $price
 * @property string|null $selling_price
 * @property string|null $currency
 * @property array|null $price_in_currencies
 * @property string|null $purchase_price
 * @property int $stock
 * @property int|null $min_stock
 * @property int|null $max_stock
 * @property int|null $reorder_point
 * @property int|null $reorder_quantity
 * @property string|null $barcode
 * @property string|null $notes
 * @property string|null $image
 * @property array|null $images
 * @property string|null $thumbnail
 * @property int|null $category_id
 * @property int|null $location_id
 * @property bool $is_active
 * @property bool $has_variants
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read int $total_stock
 * @property-read array{min: string, max: string} $price_range
 * @property-read float $profit
 * @property-read float $profit_margin
 * @property-read float $total_profit
 * @property-read Organization $organization
 * @property-read ProductCategory|null $category
 * @property-read ProductLocation|null $location
 * @property-read Collection|StockAdjustment[] $stockAdjustments
 * @property-read Collection|Supplier[] $suppliers
 * @property-read Collection|ProductOption[] $options
 * @property-read Collection|ProductVariant[] $variants
 * @property-read Collection|ProductVariant[] $activeVariants
 */
class Product extends Model
{
    use ActsAsAssembly, BelongsToOrganization, CalculatesProductProfit, HasFactory, HasProductVariants, LogsActivity, SoftDeletes, TracksStockLevels;

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'type',
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
        'reorder_point',
        'reorder_quantity',
        'barcode',
        'notes',
        'image',
        'images',
        'thumbnail',
        'category_id',
        'location_id',
        'is_active',
        'has_variants',
        'tracking_type',
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
            'reorder_point' => 'integer',
            'reorder_quantity' => 'integer',
            'is_active' => 'boolean',
            'has_variants' => 'boolean',
            'tracking_type' => TrackingType::class,
            'metadata' => 'array',
            'price_in_currencies' => 'array',
            'images' => 'array',
        ];
    }

    /**
     * Get the organization that owns the product.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the category of the product.
     *
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * Get the location of the product.
     *
     * @return BelongsTo<ProductLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class, 'location_id');
    }

    /**
     * Get all stock adjustments for this product.
     *
     * @return HasMany<StockAdjustment, $this>
     */
    public function stockAdjustments()
    {
        return $this->hasMany(StockAdjustment::class)->latest();
    }

    /**
     * Get the suppliers for this product.
     *
     * @return BelongsToMany<Supplier, $this>
     */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_supplier')
            ->withPivot(['cost_price', 'supplier_sku', 'lead_time_days', 'minimum_order_quantity', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * Get the primary supplier for this product.
     *
     * @return Supplier|null
     */
    public function primarySupplier()
    {
        return $this->suppliers()->wherePivot('is_primary', true)->first();
    }

    /**
     * Get the batches for this product.
     *
     * @return HasMany<ProductBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class)->latest();
    }

    /**
     * Get the serial numbers for this product.
     *
     * @return HasMany<ProductSerial, $this>
     */
    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class)->latest();
    }

    /**
     * Scope a query to only include products from a specific organization.
     *
     * @param  Builder<static>  $query
     * @param  int  $organizationId
     * @return Builder<static>
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope a query to only include active products.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
