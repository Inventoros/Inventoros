<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Auth\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * A Sanctum personal access token bound to one organization.
 *
 * The token is stamped with its user's active organization when it is
 * created, and every request made with it works in that organization only,
 * whatever the user later switches to in the browser. A token whose user is
 * no longer a member of its organization stops authenticating.
 *
 * @property int|null $organization_id
 * @property-read Organization|null $organization
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            // User::createToken() stamps the active organization. Anything
            // else that creates a token for a user binds it to their home
            // organization, the only one a token could reach before.
            if ($token->organization_id === null && $token->tokenable_type === User::class) {
                $token->organization_id = User::query()->whereKey($token->tokenable_id)->value('organization_id');
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
