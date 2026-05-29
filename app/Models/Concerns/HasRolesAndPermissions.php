<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role + permission behaviour for the User model.
 *
 * Extracted verbatim from the User god-object (P2-4): the role relationship,
 * admin/manager checks, and the role/permission query + mutation helpers.
 */
trait HasRolesAndPermissions
{
    /**
     * Get the roles that belong to the user.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    /**
     * Check if user is an admin of their organization.
     */
    public function isAdmin(): bool
    {
        // Check if user has the Administrator system role
        return $this->hasRole('system-administrator') || $this->role === 'admin';
    }

    /**
     * Check if user is a manager of their organization.
     */
    public function isManager(): bool
    {
        // Check if user has Administrator or Manager roles
        return $this->isAdmin() || $this->hasAnyRole(['manager', 'system-manager']);
    }

    /**
     * Check if the user has a specific role.
     */
    public function hasRole(string $roleSlug): bool
    {
        return $this->roles()->where('slug', $roleSlug)->exists();
    }

    /**
     * Check if the user has any of the given roles.
     *
     * @param  array<string>  $roleSlugs
     */
    public function hasAnyRole(array $roleSlugs): bool
    {
        return $this->roles()->whereIn('slug', $roleSlugs)->exists();
    }

    /**
     * Check if the user has all of the given roles.
     *
     * @param  array<string>  $roleSlugs
     */
    public function hasAllRoles(array $roleSlugs): bool
    {
        return $this->roles()->whereIn('slug', $roleSlugs)->count() === count($roleSlugs);
    }

    /**
     * Check if the user has a specific permission.
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
            $systemRole = Role::where('slug', 'system-'.$this->role)->first();
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
     * @param  array<Permission|string>  $permissions
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
     * @param  array<Permission|string>  $permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        // Admins have all permissions
        if ($this->isAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
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
            return array_map(fn ($p) => $p->value, Permission::cases());
        }

        $permissions = [];

        // Get permissions from base role (admin, manager, member)
        if ($this->role) {
            $systemRole = Role::where('slug', 'system-'.$this->role)->first();
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
     * @param  array<Role|string>  $roles
     */
    public function syncRoles(array $roles): void
    {
        $roleIds = collect($roles)->map(function ($role) {
            return $role instanceof Role ? $role->id : Role::where('slug', $role)->first()?->id;
        })->filter()->values()->toArray();

        $this->roles()->sync($roleIds);
    }
}
