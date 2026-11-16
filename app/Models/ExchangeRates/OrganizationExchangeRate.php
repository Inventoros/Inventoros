<?php

declare(strict_types=1);

namespace App\Models\ExchangeRates;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A rate that belongs to one organization: entered by its administrators
 * (provider "manual") or fetched from a provider whose data is licensed per
 * customer. Scoped to the organization like every tenant table.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $provider
 * @property string $base_currency
 * @property string $quote_currency
 * @property string $rate
 * @property Carbon $effective_date
 * @property Carbon $retrieved_at
 * @property int|null $entered_by
 * @property-read User|null $enteredBy
 */
class OrganizationExchangeRate extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'provider',
        'base_currency',
        'quote_currency',
        'rate',
        'effective_date',
        'retrieved_at',
        'entered_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:10',
            'effective_date' => 'date',
            'retrieved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePair(Builder $query, string $provider, string $base, string $quote): Builder
    {
        return $query->where('provider', $provider)
            ->where('base_currency', $base)
            ->where('quote_currency', $quote);
    }
}
