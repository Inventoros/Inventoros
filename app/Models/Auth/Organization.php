<?php

declare(strict_types=1);

namespace App\Models\Auth;

use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Setting;
use App\Models\User;
use App\Traits\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents an organization (tenant) in the multi-tenant system.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $city
 * @property string|null $state
 * @property string|null $zip
 * @property string|null $country
 * @property string|null $currency
 * @property string|null $timezone
 * @property string|null $date_format
 * @property string|null $time_format
 * @property bool $is_active
 * @property array|null $settings
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Inventory\Product[] $products
 */
class Organization extends Model
{
    use HasFactory, SoftDeletes, HasSlug;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'zip',
        'country',
        'currency',
        'timezone',
        'date_format',
        'time_format',
        'is_active',
        'settings',
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
            'settings' => 'array',
        ];
    }

    /**
     * No organization scope for Organization model (global uniqueness).
     *
     * @return string|null
     */
    protected function getSlugScopeColumn(): ?string
    {
        return null;
    }

    /**
     * Get the users for the organization.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the products for the organization.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Inventory\Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Scope a query to only include active organizations.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
