<?php

namespace App\Models;

use App\Enums\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'organization_id',
        'permissions',
        'is_system',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system' => 'boolean',
    ];

    /**
     * Get the organization that owns the role.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the users that have this role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user');
    }

    /**
     * Get the permission sets assigned to this role.
     */
    public function permissionSets(): BelongsToMany
    {
        return $this->belongsToMany(PermissionSet::class, 'role_permission_set')
            ->withTimestamps();
    }

    /**
     * Get all permissions including those from permission sets.
     */
    public function getAllPermissions(): array
    {
        $directPermissions = $this->permissions ?? [];

        $setPermissions = $this->permissionSets()
            ->where('is_active', true)
            ->get()
            ->flatMap(fn($set) => $set->permissions)
            ->toArray();

        return array_values(array_unique(array_merge($directPermissions, $setPermissions)));
    }

    /**
     * Apply a permission set to this role.
     */
    public function applyPermissionSet(PermissionSet $set): void
    {
        $this->permissionSets()->syncWithoutDetaching([$set->id]);
    }

    /**
     * Remove a permission set from this role.
     */
    public function removePermissionSet(PermissionSet $set): void
    {
        $this->permissionSets()->detach($set->id);
    }

    /**
     * Check if the role has a specific permission.
     */
    public function hasPermission(Permission|string $permission): bool
    {
        $permissionValue = $permission instanceof Permission ? $permission->value : $permission;

        // Check direct permissions first
        if (in_array($permissionValue, $this->permissions ?? [])) {
            return true;
        }

        // Check permission sets
        return $this->permissionSets()
            ->where('is_active', true)
            ->get()
            ->contains(fn($set) => $set->hasPermission($permissionValue));
    }

    /**
     * Check if the role has any of the given permissions.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the role has all of the given permissions.
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Grant a permission to the role.
     */
    public function grantPermission(Permission|string $permission): void
    {
        $permissionValue = $permission instanceof Permission ? $permission->value : $permission;
        $permissions = $this->permissions ?? [];

        if (!in_array($permissionValue, $permissions)) {
            $permissions[] = $permissionValue;
            $this->permissions = $permissions;
            $this->save();
        }
    }

    /**
     * Revoke a permission from the role.
     */
    public function revokePermission(Permission|string $permission): void
    {
        $permissionValue = $permission instanceof Permission ? $permission->value : $permission;
        $permissions = $this->permissions ?? [];

        if (($key = array_search($permissionValue, $permissions)) !== false) {
            unset($permissions[$key]);
            $this->permissions = array_values($permissions);
            $this->save();
        }
    }

    /**
     * Sync permissions for the role.
     */
    public function syncPermissions(array $permissions): void
    {
        $permissionValues = array_map(function ($permission) {
            return $permission instanceof Permission ? $permission->value : $permission;
        }, $permissions);

        $this->permissions = $permissionValues;
        $this->save();
    }

    /**
     * Scope a query to only include roles from a specific organization.
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope a query to only include non-system roles.
     */
    public function scopeCustom($query)
    {
        return $query->where('is_system', false);
    }
}
