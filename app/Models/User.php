<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use App\Models\Auth\Organization;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read bool $is_admin
 * @property-read bool $is_manager
 * @property-read \App\Models\Auth\Organization $organization
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Role[] $roles
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, LogsActivity, HasApiTokens;

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
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
        ];
    }

    /**
     * Get is_admin attribute for backward compatibility.
     *
     * @return bool
     */
    public function getIsAdminAttribute(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Get is_manager attribute.
     *
     * @return bool
     */
    public function getIsManagerAttribute(): bool
    {
        return in_array($this->role, ['admin', 'manager']);
    }

    /**
     * Get the organization that owns the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the roles that belong to the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    /**
     * Check if user is an admin of their organization.
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        // Check if user has the Administrator system role
        return $this->hasRole('system-administrator') || $this->role === 'admin';
    }

    /**
     * Check if user is a manager of their organization.
     *
     * @return bool
     */
    public function isManager(): bool
    {
        // Check if user has Administrator or Manager roles
        return $this->isAdmin() || $this->hasAnyRole(['manager', 'system-manager']);
    }

    /**
     * Check if the user has a specific role.
     *
     * @param string $roleSlug
     * @return bool
     */
    public function hasRole(string $roleSlug): bool
    {
        return $this->roles()->where('slug', $roleSlug)->exists();
    }

    /**
     * Check if the user has any of the given roles.
     *
     * @param array<string> $roleSlugs
     * @return bool
     */
    public function hasAnyRole(array $roleSlugs): bool
    {
        return $this->roles()->whereIn('slug', $roleSlugs)->exists();
    }

    /**
     * Check if the user has all of the given roles.
     *
     * @param array<string> $roleSlugs
     * @return bool
     */
    public function hasAllRoles(array $roleSlugs): bool
    {
        return $this->roles()->whereIn('slug', $roleSlugs)->count() === count($roleSlugs);
    }

    /**
     * Check if the user has a specific permission.
     *
     * @param \App\Enums\Permission|string $permission
     * @return bool
     */
    public function hasPermission(Permission|string $permission): bool
    {
        // Admins have all permissions
        if ($this->isAdmin()) {
            return true;
        }

        $permissionValue = $permission instanceof Permission ? $permission->value : $permission;

        // Check base role (admin, manager, member) permissions
        if ($this->role) {
            $systemRole = Role::where('slug', 'system-' . $this->role)->first();
            if ($systemRole && $systemRole->hasPermission($permissionValue)) {
                return true;
            }
        }

        // Check custom roles for the permission
        return $this->roles->contains(function ($role) use ($permissionValue) {
            return $role->hasPermission($permissionValue);
        });
    }

    /**
     * Check if the user has any of the given permissions.
     *
     * @param array<\App\Enums\Permission|string> $permissions
     * @return bool
     */
    public function hasAnyPermission(array $permissions): bool
    {
        // Admins have all permissions
        if ($this->isAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user has all of the given permissions.
     *
     * @param array<\App\Enums\Permission|string> $permissions
     * @return bool
     */
    public function hasAllPermissions(array $permissions): bool
    {
        // Admins have all permissions
        if ($this->isAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all permissions for the user across all their roles.
     *
     * @return array<string>
     */
    public function getAllPermissions(): array
    {
        // Admins have all permissions
        if ($this->isAdmin()) {
            return array_map(fn($p) => $p->value, Permission::cases());
        }

        $permissions = [];

        // Get permissions from base role (admin, manager, member)
        if ($this->role) {
            $systemRole = Role::where('slug', 'system-' . $this->role)->first();
            if ($systemRole && $systemRole->permissions) {
                $permissions = array_merge($permissions, $systemRole->permissions);
            }
        }

        // Get permissions from custom roles
        foreach ($this->roles as $role) {
            if ($role->permissions) {
                $permissions = array_merge($permissions, $role->permissions);
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * Assign a role to the user.
     *
     * @param \App\Models\Role|string $role
     * @return void
     */
    public function assignRole(Role|string $role): void
    {
        if ($role instanceof Role) {
            $this->roles()->syncWithoutDetaching([$role->id]);
        } else {
            $roleModel = Role::where('slug', $role)->first();
            if ($roleModel) {
                $this->roles()->syncWithoutDetaching([$roleModel->id]);
            }
        }
    }

    /**
     * Remove a role from the user.
     *
     * @param \App\Models\Role|string $role
     * @return void
     */
    public function removeRole(Role|string $role): void
    {
        if ($role instanceof Role) {
            $this->roles()->detach($role->id);
        } else {
            $roleModel = Role::where('slug', $role)->first();
            if ($roleModel) {
                $this->roles()->detach($roleModel->id);
            }
        }
    }

    /**
     * Sync roles for the user.
     *
     * @param array<\App\Models\Role|string> $roles
     * @return void
     */
    public function syncRoles(array $roles): void
    {
        $roleIds = collect($roles)->map(function ($role) {
            return $role instanceof Role ? $role->id : Role::where('slug', $role)->first()?->id;
        })->filter()->values()->toArray();

        $this->roles()->sync($roleIds);
    }

    /**
     * Scope a query to only include users from a specific organization.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
