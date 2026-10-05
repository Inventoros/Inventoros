<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Auth\Organization;
use App\Models\Auth\OrganizationMembership;
use App\Models\Concerns\HasRolesAndPermissions;
use App\Models\Concerns\InteractsWithWarehouses;
use App\Traits\LogsActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * Represents a user in the system.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property int|null $organization_id The ACTIVE organization: the home organization
 *                                    unless the session switched, or the API token is bound,
 *                                    to another one the user is a member of (see
 *                                    App\Services\Organizations\ActiveOrganization).
 * @property string|null $role The base role in the active organization.
 * @property array|null $notification_preferences
 * @property Carbon|null $email_verified_at
 * @property string|null $two_factor_secret
 * @property bool $two_factor_enabled
 * @property string|null $two_factor_recovery_codes
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read bool $is_admin
 * @property-read bool $is_manager
 * @property-read Organization $organization
 * @property-read Collection|Role[] $roles
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRolesAndPermissions, InteractsWithWarehouses, LogsActivity, Notifiable {
        HasApiTokens::withAccessToken as private sanctumWithAccessToken;
    }

    /**
     * The organization and role this instance was switched to, when it is not
     * the home organization stored on the row. Kept apart from the attributes
     * so refresh() can re-apply it and the home values are never lost.
     *
     * @var array{organization_id: int, role: string|null}|null
     */
    private ?array $activeOrganization = null;

    /**
     * The stored home organization and role, captured on the first switch.
     *
     * @var array{organization_id: int|null, role: string|null}|null
     */
    private ?array $homeOrganization = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'organization_id',
        'role',
        'notification_preferences',
        'dashboard_widgets',
        'two_factor_secret',
        'two_factor_enabled',
        'two_factor_recovery_codes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
            'two_factor_enabled' => 'boolean',
            'dashboard_widgets' => 'array',
        ];
    }

    /**
     * Block authenticated users from changing their own role or
     * organization_id via mass assignment.
     *
     * Today no controller intentionally does that, but if a future PR
     * wires $request->user()->update($request->validated()) on the
     * profile-update endpoint and the validator allows either field,
     * any authenticated user could elevate themselves to admin or
     * relocate into another tenant. This guard makes that future
     * mistake fail loudly at save time instead of silently succeeding.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            // An instance switched into another organization holds that
            // organization's id and role in memory; writing them would move
            // the account's home. Memberships change through
            // OrganizationMembershipService instead.
            if ($user->activeOrganization !== null && $user->isDirty(['organization_id', 'role'])) {
                throw new \RuntimeException('Change organization memberships through OrganizationMembershipService.');
            }

            if (! $user->exists || ! auth()->check() || (int) $user->id !== (int) auth()->id()) {
                return;
            }

            if ($user->isDirty('role')) {
                throw new \RuntimeException('Users cannot change their own role.');
            }
            if ($user->isDirty('organization_id')) {
                throw new \RuntimeException('Users cannot change their own organization.');
            }
        });

        // Keep the home membership row in step with users.organization_id
        // and users.role, so organization_user lists every member.
        static::created(fn (self $user) => $user->syncHomeMembership(null));
        static::updated(function (self $user): void {
            if ($user->wasChanged(['organization_id', 'role'])) {
                $previous = $user->getOriginal('organization_id');
                $user->syncHomeMembership($previous === null ? null : (int) $previous);
            }
        });
    }

    /**
     * Mirror the home organization and role into organization_user. Moving a
     * user to another home organization removes the old home membership, as
     * changing organization_id always meant.
     */
    private function syncHomeMembership(?int $previousOrganizationId): void
    {
        $organizationId = $this->getAttributes()['organization_id'] ?? null;

        if ($previousOrganizationId !== null && $previousOrganizationId !== (int) $organizationId) {
            OrganizationMembership::query()
                ->where('user_id', $this->getKey())
                ->where('organization_id', $previousOrganizationId)
                ->delete();
        }

        if ($organizationId === null || ! Organization::withTrashed()->whereKey($organizationId)->exists()) {
            return;
        }

        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => (int) $organizationId, 'user_id' => $this->getKey()],
            ['role' => $this->getAttributes()['role'] ?? null],
        );
    }

    /**
     * The organization that owns the account (users.organization_id as
     * stored), whichever organization is active.
     */
    public function homeOrganizationId(): ?int
    {
        $id = $this->homeOrganization !== null
            ? $this->homeOrganization['organization_id']
            : ($this->getAttributes()['organization_id'] ?? null);

        return $id === null ? null : (int) $id;
    }

    /**
     * The base role in the home organization (users.role as stored).
     */
    public function homeRole(): ?string
    {
        return $this->homeOrganization !== null
            ? $this->homeOrganization['role']
            : ($this->getAttributes()['role'] ?? null);
    }

    /**
     * Whether this instance works in an organization other than its home.
     */
    public function isInGuestOrganization(): bool
    {
        return $this->activeOrganization !== null;
    }

    /**
     * Make $organizationId this instance's active organization, with $role as
     * its base role there (the home organization always takes the stored home
     * role). Memory only: nothing is written, and the attributes stay clean,
     * so saving other changes never moves the account.
     *
     * @internal Called by ActiveOrganization after it has checked the
     *           membership. Everything else switches through that service.
     */
    public function useOrganization(?int $organizationId, ?string $role): static
    {
        $this->homeOrganization ??= [
            'organization_id' => $this->homeOrganizationId(),
            'role' => $this->homeRole(),
        ];

        $isHome = $organizationId === $this->homeOrganization['organization_id'];
        $this->activeOrganization = $isHome ? null : ['organization_id' => $organizationId, 'role' => $role];

        $this->applyActiveOrganization($organizationId, $isHome ? $this->homeOrganization['role'] : $role);

        return $this;
    }

    private function applyActiveOrganization(?int $organizationId, ?string $role): void
    {
        $this->forceFill(['organization_id' => $organizationId, 'role' => $role]);
        $this->syncOriginalAttributes(['organization_id', 'role']);

        // Relations loaded for the previous organization must not answer for
        // this one (roles and warehouses are per organization).
        $this->unsetRelation('organization');
        $this->unsetRelation('roles');
        $this->unsetRelation('warehouses');
    }

    /**
     * Reload from the database without losing the active organization.
     *
     * @return $this
     */
    public function refresh()
    {
        parent::refresh();

        if ($this->homeOrganization !== null) {
            $id = $this->getAttributes()['organization_id'] ?? null;
            $this->homeOrganization = [
                'organization_id' => $id === null ? null : (int) $id,
                'role' => $this->getAttributes()['role'] ?? null,
            ];
        }

        if ($this->activeOrganization !== null) {
            $this->applyActiveOrganization($this->activeOrganization['organization_id'], $this->activeOrganization['role']);
        }

        return $this;
    }

    /**
     * Set the current access token. A personal access token binds the user to
     * the organization it was created in, for this request.
     *
     * @param  \Laravel\Sanctum\Contracts\HasAbilities  $accessToken
     * @return $this
     */
    public function withAccessToken($accessToken)
    {
        $this->sanctumWithAccessToken($accessToken);

        if ($accessToken instanceof PersonalAccessToken && $accessToken->exists === true) {
            app(\App\Services\Organizations\ActiveOrganization::class)->bindToToken($this, $accessToken);
        }

        return $this;
    }

    /**
     * Create a personal access token bound to the active organization.
     *
     * @param  array<int, string>  $abilities
     */
    public function createToken(string $name, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $plainTextToken = $this->generateTokenString();

        /** @var PersonalAccessToken $token */
        $token = $this->tokens()->make([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);
        $token->organization_id = $this->organization_id;
        $token->save();

        return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }

    /**
     * The user's API tokens bound to the active organization.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<PersonalAccessToken, $this>
     */
    public function organizationTokens(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        $tokens = $this->tokens();

        return $this->organization_id === null
            ? $tokens->whereNull('organization_id')
            : $tokens->where('organization_id', (int) $this->organization_id);
    }

    /**
     * Every membership row, the home organization's included.
     *
     * @return HasMany<OrganizationMembership, $this>
     */
    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /**
     * Get is_admin attribute for backward compatibility.
     */
    public function getIsAdminAttribute(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Get is_manager attribute.
     */
    public function getIsManagerAttribute(): bool
    {
        return in_array($this->role, ['admin', 'manager']);
    }

    /**
     * Get the user's active organization (see organization_id).
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope a query to only include users whose HOME organization is the
     * given one. Members who belong to it through a further membership are
     * not included: OrganizationMembershipService::members() lists those too.
     *
     * @param  Builder<static>  $query
     * @param  int  $organizationId
     * @return Builder<static>
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
