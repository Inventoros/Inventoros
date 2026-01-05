<?php

namespace App\Models;

use App\Enums\Permission;
use App\Models\Auth\Organization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class PermissionSet extends Model
{
    use HasFactory;

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
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (PermissionSet $set) {
            if (empty($set->slug)) {
                $set->slug = Str::slug($set->name);

                // Ensure unique slug
                $count = static::where('slug', 'like', $set->slug . '%')->count();
                if ($count > 0) {
                    $set->slug = $set->slug . '-' . ($count + 1);
                }
            }
        });
    }

    /**
     * Get the organization that owns this permission set.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the roles that have this permission set.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission_set')
            ->withTimestamps();
    }

    /**
     * Check if this set contains a specific permission.
     */
    public function hasPermission(Permission|string $permission): bool
    {
        $value = $permission instanceof Permission ? $permission->value : $permission;
        return in_array($value, $this->permissions ?? [], true);
    }

    /**
     * Get permission count.
     */
    public function getPermissionCountAttribute(): int
    {
        return count($this->permissions ?? []);
    }

    /**
     * Get permissions as Permission enum objects.
     */
    public function getPermissionEnumsAttribute(): array
    {
        return array_filter(array_map(function ($value) {
            return Permission::tryFrom($value);
        }, $this->permissions ?? []));
    }

    /**
     * Scope for templates only.
     */
    public function scopeTemplates($query)
    {
        return $query->where('is_template', true);
    }

    /**
     * Scope for custom (non-template) sets.
     */
    public function scopeCustom($query)
    {
        return $query->where('is_template', false);
    }

    /**
     * Scope for active sets.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for organization.
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
     */
    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Get default permission set templates.
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
