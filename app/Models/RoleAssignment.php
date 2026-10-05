<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A role held by a user in one organization (a role_user row).
 *
 * Every assignment names its organization. Attached from the user's side the
 * relation stamps the user's active organization; attached from the role's
 * side (`$role->users()->attach(...)`), it takes the role's organization, or,
 * for a system role, the user's home organization.
 *
 * @property int $role_id
 * @property int $user_id
 * @property int $organization_id
 */
class RoleAssignment extends Pivot
{
    protected $table = 'role_user';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            if ($assignment->organization_id !== null) {
                return;
            }

            $assignment->organization_id = Role::query()->whereKey($assignment->role_id)->value('organization_id')
                ?? User::query()->whereKey($assignment->user_id)->value('organization_id');
        });
    }
}
