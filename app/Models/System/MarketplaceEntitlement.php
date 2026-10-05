<?php

declare(strict_types=1);

namespace App\Models\System;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * An organization's last signed entitlement document from the marketplace.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $install_id
 * @property string|null $document Raw JSON as the marketplace served it
 * @property \Illuminate\Support\Carbon|null $fetched_at Last successful refresh
 * @property \Illuminate\Support\Carbon|null $attempted_at Last refresh attempt
 * @property string|null $last_error
 */
class MarketplaceEntitlement extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'install_id',
        'document',
        'fetched_at',
        'attempted_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'attempted_at' => 'datetime',
        ];
    }
}
