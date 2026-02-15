<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use App\Models\Auth\Organization;
use App\Traits\HasSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Represents a role that can be assigned to users.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int|null $organization_id
 * @property array|null $permissions
 * @property bool $is_system
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Auth\Organization|null $organization
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PermissionSet[] $permissionSets
 */
class Role extends Model
{
    use HasSlug;

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
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the users that have this role.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user');
    }

    /**
     * Get the permission sets assigned to this role.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\PermissionSet, $this>
     */
    public function permissionSets(): BelongsToMany
    {
        return $this->belongsToMany(PermissionSet::class, 'role_permission_set')
            ->withTimestamps();
    }

    /**
     * Get all permissions including those from permission sets.
     *
     * @return array<string>
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
     *
     * @param \App\Models\PermissionSet $set
     * @return void
     */
    public function applyPermissionSet(PermissionSet $set): void
    {
        $this->permissionSets()->syncWithoutDetaching([$set->id]);
    }

    /**
     * Remove a permission set from this role.
     *
     * @param \App\Models\PermissionSet $set
     * @return void
     */
    public function removePermissionSet(PermissionSet $set): void
    {
        $this->permissionSets()->detach($set->id);
    }

    /**
     * Check if the role has a specific permission.
     *
     * @param \App\Enums\Permission|string $permission
     * @return bool
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
     *
     * @param array<\App\Enums\Permission|string> $permissions
     * @return bool
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
     *
     * @param array<\App\Enums\Permission|string> $permissions
     * @return bool
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
     *
     * @param \App\Enums\Permission|string $permission
     * @return void
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
     *
     * @param \App\Enums\Permission|string $permission
     * @return void
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
     *
     * @param array<\App\Enums\Permission|string> $permissions
     * @return void
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
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope a query to only include non-system roles.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeCustom($query)
    {
        return $query->where('is_system', false);
    }
}
