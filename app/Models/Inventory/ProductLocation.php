<?php

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the products at this location.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'location_id');
    }

    /**
     * Scope a query to only include locations from a specific organization.
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope a query to only include active locations.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the full location identifier.
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
