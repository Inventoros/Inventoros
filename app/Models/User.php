<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Auth\Organization;
use App\Models\Concerns\HasRolesAndPermissions;
use App\Models\Concerns\InteractsWithWarehouses;
use App\Traits\LogsActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Represents a user in the system.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property int|null $organization_id
 * @property string|null $role
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
    use HasApiTokens, HasFactory, HasRolesAndPermissions, InteractsWithWarehouses, LogsActivity, Notifiable;

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
     * Get the organization that owns the user.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope a query to only include users from a specific organization.
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
