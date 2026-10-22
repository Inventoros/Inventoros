<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A hold on stock that is on hand but promised to an owner (a plugin slug, or
 * "core"). Rows are written only through App\Services\StockReservationService;
 * the table is not a plugin table.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $location_id
 * @property int|null $product_serial_id
 * @property int|null $product_batch_id
 * @property int $quantity
 * @property string $owner_type
 * @property string $owner_reference
 * @property string $scope_key
 * @property Carbon|null $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $consumed_by_type
 * @property int|null $consumed_by_id
 * @property Carbon|null $released_at
 * @property string|null $release_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read ProductLocation|null $location
 * @property-read ProductSerial|null $serial
 * @property-read ProductBatch|null $batch
 */
class StockReservation extends Model
{
    use BelongsToOrganization;

    /**
     * Release reasons recorded on released_at rows.
     */
    public const RELEASED = 'released';

    public const EXPIRED = 'expired';

    public const UNINSTALLED = 'uninstalled';

    public const ADMIN = 'admin';

    /**
     * The owner_type of holds core itself places.
     */
    public const CORE_OWNER = 'core';

    /**
     * Nothing is mass assignable: rows are written field by field by
     * StockReservationService, never from a request.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * The scope key for a hold's target, used by the idempotency index.
     */
    public static function scopeKeyFor(int $productId, ?int $variantId, ?int $locationId, ?int $serialId, ?int $batchId): string
    {
        return sprintf('p%d:v%d:l%d:s%d:b%d', $productId, $variantId ?? 0, $locationId ?? 0, $serialId ?? 0, $batchId ?? 0);
    }

    /**
     * Holds that still count: neither consumed nor released, and not past
     * their expiry (the sweeper releases expired holds later, but they stop
     * counting the moment they expire).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
            ->whereNull('released_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Whether this hold still counts.
     */
    public function isActive(): bool
    {
        return $this->consumed_at === null
            && $this->released_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * active, expired, consumed or released.
     */
    public function status(): string
    {
        return match (true) {
            $this->consumed_at !== null => 'consumed',
            $this->released_at !== null => 'released',
            $this->expires_at !== null && ! $this->expires_at->isFuture() => 'expired',
            default => 'active',
        };
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<ProductLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class, 'location_id');
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

    /**
     * What consumed the hold (an order, an adjustment), when recorded.
     *
     * @return MorphTo<Model, $this>
     */
    public function consumedBy(): MorphTo
    {
        return $this->morphTo('consumed_by');
    }
}
