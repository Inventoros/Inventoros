<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Auth\Organization;
use App\Models\Order\Order;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a customer in the system.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $code
 * @property string|null $company_name
 * @property string|null $contact_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $billing_address
 * @property string|null $billing_city
 * @property string|null $billing_state
 * @property string|null $billing_zip_code
 * @property string|null $billing_country
 * @property string|null $shipping_address
 * @property string|null $shipping_city
 * @property string|null $shipping_state
 * @property string|null $shipping_zip_code
 * @property string|null $shipping_country
 * @property string|null $tax_id
 * @property string|null $payment_terms
 * @property string|null $credit_limit
 * @property string|null $currency
 * @property string|null $notes
 * @property array|null $metadata
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read string $full_billing_address
 * @property-read string $full_shipping_address
 * @property-read \App\Models\Auth\Organization $organization
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Order\Order[] $orders
 */
class Customer extends Model
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
        'company_name',
        'contact_name',
        'email',
        'phone',
        'billing_address',
        'billing_city',
        'billing_state',
        'billing_zip_code',
        'billing_country',
        'shipping_address',
        'shipping_city',
        'shipping_state',
        'shipping_zip_code',
        'shipping_country',
        'tax_id',
        'payment_terms',
        'credit_limit',
        'currency',
        'notes',
        'metadata',
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
            'metadata' => 'array',
            'credit_limit' => 'decimal:2',
        ];
    }

    /**
     * Get the organization that owns the customer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the orders for this customer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Order\Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Scope a query to only include customers from a specific organization.
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
     * Scope a query to only include active customers.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to search customers.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param string $term
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeSearch($query, $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('company_name', 'like', "%{$term}%")
                ->orWhere('contact_name', 'like', "%{$term}%");
        });
    }

    /**
     * Get the full billing address as a single string.
     *
     * @return string
     */
    public function getFullBillingAddressAttribute(): string
    {
        $parts = array_filter([
            $this->billing_address,
            $this->billing_city,
            $this->billing_state,
            $this->billing_zip_code,
            $this->billing_country,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get the full shipping address as a single string.
     *
     * @return string
     */
    public function getFullShippingAddressAttribute(): string
    {
        $parts = array_filter([
            $this->shipping_address,
            $this->shipping_city,
            $this->shipping_state,
            $this->shipping_zip_code,
            $this->shipping_country,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Check if billing and shipping addresses are the same.
     *
     * @return bool
     */
    public function hasSameAddresses(): bool
    {
        return $this->full_billing_address === $this->full_shipping_address;
    }
}
