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
 * Represents a physical location for storing products.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $code
 * @property string|null $description
 * @property string|null $aisle
 * @property string|null $shelf
 * @property string|null $bin
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read string $full_location
 * @property-read \App\Models\Auth\Organization $organization
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Inventory\Product[] $products
 */
class ProductLocation extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'aisle',
        'shelf',
        'bin',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the organization that owns the location.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the products at this location.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Inventory\Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'location_id');
    }

    /**
     * Scope a query to only include locations from a specific organization.
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
     * Scope a query to only include active locations.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the full location identifier.
     *
     * @return string
     */
    public function getFullLocationAttribute(): string
    {
        $parts = array_filter([
            $this->aisle ? "Aisle {$this->aisle}" : null,
            $this->shelf ? "Shelf {$this->shelf}" : null,
            $this->bin ? "Bin {$this->bin}" : null,
        ]);

        return implode(', ', $parts) ?: $this->name;
    }
}
