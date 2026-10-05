<?php

declare(strict_types=1);

namespace App\Models\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's membership of an organization, with their base role there
 * (admin, manager or member, as `users.role`).
 *
 * Every user has one for their home organization (`users.organization_id`),
 * kept in step with `users.role`; further memberships let the same account
 * switch into other organizations. Infrastructure, like User and Role: no
 * OrganizationScope, because memberships are read while the user is being
 * resolved. Change them through OrganizationMembershipService, which checks,
 * cleans up and audits.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string|null $role
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User $user
 */
class OrganizationMembership extends Model
{
    protected $table = 'organization_user';

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
    ];

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
