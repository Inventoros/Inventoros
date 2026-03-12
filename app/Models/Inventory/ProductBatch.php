<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a batch of a product for batch tracking.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $product_id
 * @property string $batch_number
 * @property int $quantity
 * @property \Illuminate\Support\Carbon|null $manufactured_date
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Auth\Organization $organization
 * @property-read \App\Models\Inventory\Product $product
 */
class ProductBatch extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'product_id',
        'batch_number',
        'quantity',
        'manufactured_date',
        'expiry_date',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'manufactured_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    /**
     * Get the organization that owns this batch.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the product that owns this batch.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Scope a query to only include batches from a specific organization.
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
     * Check if the batch is expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    /**
     * Generate a batch number automatically.
     *
     * Format: BATCH-YYYYMMDD-XXXX
     *
     * @param int $organizationId
     * @return string
     */
    public static function generateBatchNumber(int $organizationId): string
    {
        $date = now()->format('Ymd');
        $prefix = "BATCH-{$date}-";

        $lastBatch = static::where('organization_id', $organizationId)
            ->where('batch_number', 'like', "{$prefix}%")
            ->orderBy('batch_number', 'desc')
            ->first();

        if ($lastBatch) {
            $lastNumber = (int) substr($lastBatch->batch_number, strlen($prefix));
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
