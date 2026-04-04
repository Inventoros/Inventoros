<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Auth\Organization;
use App\Models\Inventory\ProductLocation;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $code
 * @property string|null $description
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $city
 * @property string|null $province
 * @property string|null $postal_code
 * @property string $country
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $manager_name
 * @property string|null $timezone
 * @property string|null $currency
 * @property bool $is_default
 * @property bool $is_active
 * @property int $priority
 */
class Warehouse extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'address_line_1',
        'address_line_2',
        'city',
        'province',
        'postal_code',
        'country',
        'phone',
        'email',
        'manager_name',
        'timezone',
        'currency',
        'is_default',
        'is_active',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(ProductLocation::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'warehouse_user')->withTimestamps();
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the full address as a string.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->city,
            $this->province,
            $this->postal_code,
            $this->country,
        ]);

        return implode(', ', $parts);
    }
}
