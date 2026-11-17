<?php

declare(strict_types=1);

namespace App\Models\ExchangeRates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A rate from a shareable provider (public data, identical for every
 * customer), stored once for every organization: 1 base_currency = rate
 * quote_currency on effective_date. Holds no tenant data.
 *
 * @property int $id
 * @property string $provider
 * @property string $base_currency
 * @property string $quote_currency
 * @property string $rate
 * @property Carbon $effective_date
 * @property Carbon $retrieved_at
 */
class ExchangeRate extends Model
{
    protected $fillable = [
        'provider',
        'base_currency',
        'quote_currency',
        'rate',
        'effective_date',
        'retrieved_at',
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
