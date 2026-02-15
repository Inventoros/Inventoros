<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use App\Models\Auth\Organization;
use App\Traits\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Represents a collection of permissions that can be assigned to roles.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property array|null $permissions
 * @property string|null $category
 * @property string|null $icon
 * @property bool $is_template
 * @property bool $is_active
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read int $permission_count
 * @property-read array<\App\Enums\Permission> $permission_enums
 * @property-read \App\Models\Auth\Organization|null $organization
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Role[] $roles
 */
class PermissionSet extends Model
{
    use HasFactory, HasSlug;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'permissions',
        'category',
        'icon',
        'is_template',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_template' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * Get the organization that owns this permission set.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Auth\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the roles that have this permission set.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission_set')
            ->withTimestamps();
    }

    /**
     * Check if this set contains a specific permission.
     *
     * @param \App\Enums\Permission|string $permission
     * @return bool
     */
    public function hasPermission(Permission|string $permission): bool
    {
        $value = $permission instanceof Permission ? $permission->value : $permission;
        return in_array($value, $this->permissions ?? [], true);
    }

    /**
     * Get permission count.
     *
     * @return int
     */
    public function getPermissionCountAttribute(): int
    {
        return count($this->permissions ?? []);
    }

    /**
     * Get permissions as Permission enum objects.
     *
     * @return array<\App\Enums\Permission>
     */
    public function getPermissionEnumsAttribute(): array
    {
        return array_filter(array_map(function ($value) {
            return Permission::tryFrom($value);
        }, $this->permissions ?? []));
    }

    /**
     * Scope for templates only.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeTemplates($query)
    {
        return $query->where('is_template', true);
    }

    /**
     * Scope for custom (non-template) sets.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeCustom($query)
    {
        return $query->where('is_template', false);
    }

    /**
     * Scope for active sets.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for organization.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where(function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId)
              ->orWhere('is_template', true);
        });
    }

    /**
     * Scope by category.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Get default permission set templates.
     *
     * @return array<int, array{name: string, slug: string, description: string, category: string, icon: string, permissions: array<string>}>
     */
    public static function getDefaultTemplates(): array
    {
        return [
            [
                'name' => 'Inventory Manager',
                'slug' => 'inventory-manager',
                'description' => 'Full access to inventory management including products, categories, and locations.',
                'category' => 'inventory',
                'icon' => 'cube',
                'permissions' => [
                    Permission::VIEW_PRODUCTS->value,
                    Permission::CREATE_PRODUCTS->value,
                    Permission::EDIT_PRODUCTS->value,
                    Permission::DELETE_PRODUCTS->value,
                    Permission::MANAGE_CATEGORIES->value,
                    Permission::MANAGE_LOCATIONS->value,
                    Permission::VIEW_REPORTS->value,
                    Permission::EXPORT_DATA->value,
                    Permission::IMPORT_DATA->value,
                ],
            ],
            [
                'name' => 'Order Processor',
                'slug' => 'order-processor',
                'description' => 'Manage orders from creation to completion.',
                'category' => 'orders',
                'icon' => 'shopping-cart',
                'permissions' => [
                    Permission::VIEW_PRODUCTS->value,
                    Permission::VIEW_ORDERS->value,
                    Permission::CREATE_ORDERS->value,
                    Permission::EDIT_ORDERS->value,
                    Permission::APPROVE_ORDERS->value,
                ],
            ],
            [
                'name' => 'Warehouse Staff',
                'slug' => 'warehouse-staff',
                'description' => 'View products and manage stock levels.',
                'category' => 'inventory',
                'icon' => 'archive',
                'permissions' => [
                    Permission::VIEW_PRODUCTS->value,
                    Permission::EDIT_PRODUCTS->value,
                    Permission::VIEW_ORDERS->value,
                    Permission::VIEW_PURCHASE_ORDERS->value,
                    Permission::RECEIVE_PURCHASE_ORDERS->value,
                ],
            ],
            [
                'name' => 'Purchasing Agent',
                'slug' => 'purchasing-agent',
                'description' => 'Manage suppliers and purchase orders.',
                'category' => 'purchasing',
                'icon' => 'truck',
                'permissions' => [
                    Permission::VIEW_PRODUCTS->value,
                    Permission::VIEW_SUPPLIERS->value,
                    Permission::CREATE_SUPPLIERS->value,
                    Permission::EDIT_SUPPLIERS->value,
                    Permission::VIEW_PURCHASE_ORDERS->value,
                    Permission::CREATE_PURCHASE_ORDERS->value,
                    Permission::EDIT_PURCHASE_ORDERS->value,
                    Permission::RECEIVE_PURCHASE_ORDERS->value,
                ],
            ],
            [
                'name' => 'Read-Only Auditor',
                'slug' => 'read-only-auditor',
                'description' => 'View-only access to all data for auditing purposes.',
                'category' => 'admin',
                'icon' => 'eye',
                'permissions' => [
                    Permission::VIEW_USERS->value,
                    Permission::VIEW_ROLES->value,
                    Permission::VIEW_PRODUCTS->value,
                    Permission::VIEW_SUPPLIERS->value,
                    Permission::VIEW_PURCHASE_ORDERS->value,
                    Permission::VIEW_ORDERS->value,
                    Permission::VIEW_SETTINGS->value,
                    Permission::VIEW_REPORTS->value,
                    Permission::VIEW_ACTIVITY_LOG->value,
                ],
            ],
            [
                'name' => 'User Administrator',
                'slug' => 'user-administrator',
                'description' => 'Manage users and their access permissions.',
                'category' => 'admin',
                'icon' => 'users',
                'permissions' => [
                    Permission::VIEW_USERS->value,
                    Permission::CREATE_USERS->value,
                    Permission::EDIT_USERS->value,
                    Permission::DELETE_USERS->value,
                    Permission::VIEW_ROLES->value,
                    Permission::CREATE_ROLES->value,
                    Permission::EDIT_ROLES->value,
                    Permission::DELETE_ROLES->value,
                    Permission::VIEW_ACTIVITY_LOG->value,
                ],
            ],
            [
                'name' => 'Reports Viewer',
                'slug' => 'reports-viewer',
                'description' => 'Access to reports and data export functionality.',
                'category' => 'reports',
                'icon' => 'chart-bar',
                'permissions' => [
                    Permission::VIEW_PRODUCTS->value,
                    Permission::VIEW_ORDERS->value,
                    Permission::VIEW_REPORTS->value,
                    Permission::EXPORT_DATA->value,
                    Permission::VIEW_ACTIVITY_LOG->value,
                ],
            ],
        ];
    }
}
