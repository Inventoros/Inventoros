<?php

namespace App\Enums;

enum Permission: string
{
    // User Management
    case VIEW_USERS = 'view_users';
    case CREATE_USERS = 'create_users';
    case EDIT_USERS = 'edit_users';
    case DELETE_USERS = 'delete_users';

    // Role Management
    case VIEW_ROLES = 'view_roles';
    case CREATE_ROLES = 'create_roles';
    case EDIT_ROLES = 'edit_roles';
    case DELETE_ROLES = 'delete_roles';

    // Product Management
    case VIEW_PRODUCTS = 'view_products';
    case CREATE_PRODUCTS = 'create_products';
    case EDIT_PRODUCTS = 'edit_products';
    case DELETE_PRODUCTS = 'delete_products';
    case MANAGE_CATEGORIES = 'manage_categories';
    case MANAGE_LOCATIONS = 'manage_locations';

    // Order Management
    case VIEW_ORDERS = 'view_orders';
    case CREATE_ORDERS = 'create_orders';
    case EDIT_ORDERS = 'edit_orders';
    case DELETE_ORDERS = 'delete_orders';
    case APPROVE_ORDERS = 'approve_orders';

    // Settings
    case VIEW_SETTINGS = 'view_settings';
    case EDIT_SETTINGS = 'edit_settings';
    case MANAGE_ORGANIZATION = 'manage_organization';

    // Plugins
    case VIEW_PLUGINS = 'view_plugins';
    case MANAGE_PLUGINS = 'manage_plugins';

    // Reports & Data
    case VIEW_REPORTS = 'view_reports';
    case EXPORT_DATA = 'export_data';
    case IMPORT_DATA = 'import_data';
    case VIEW_ACTIVITY_LOG = 'view_activity_log';

    /**
     * Get permission label for display
     */
    public function label(): string
    {
        return match($this) {
            self::VIEW_USERS => 'View Users',
            self::CREATE_USERS => 'Create Users',
            self::EDIT_USERS => 'Edit Users',
            self::DELETE_USERS => 'Delete Users',

            self::VIEW_ROLES => 'View Roles',
            self::CREATE_ROLES => 'Create Roles',
            self::EDIT_ROLES => 'Edit Roles',
            self::DELETE_ROLES => 'Delete Roles',

            self::VIEW_PRODUCTS => 'View Products',
            self::CREATE_PRODUCTS => 'Create Products',
            self::EDIT_PRODUCTS => 'Edit Products',
            self::DELETE_PRODUCTS => 'Delete Products',
            self::MANAGE_CATEGORIES => 'Manage Categories',
            self::MANAGE_LOCATIONS => 'Manage Locations',

            self::VIEW_ORDERS => 'View Orders',
            self::CREATE_ORDERS => 'Create Orders',
            self::EDIT_ORDERS => 'Edit Orders',
            self::DELETE_ORDERS => 'Delete Orders',
            self::APPROVE_ORDERS => 'Approve Orders',

            self::VIEW_SETTINGS => 'View Settings',
            self::EDIT_SETTINGS => 'Edit Settings',
            self::MANAGE_ORGANIZATION => 'Manage Organization',

            self::VIEW_PLUGINS => 'View Plugins',
            self::MANAGE_PLUGINS => 'Manage Plugins',

            self::VIEW_REPORTS => 'View Reports',
            self::EXPORT_DATA => 'Export Data',
            self::IMPORT_DATA => 'Import Data',
            self::VIEW_ACTIVITY_LOG => 'View Activity Log',
        };
    }

    /**
     * Get permission description
     */
    public function description(): string
    {
        return match($this) {
            self::VIEW_USERS => 'Can view user list and details',
            self::CREATE_USERS => 'Can create new users',
            self::EDIT_USERS => 'Can edit existing users',
            self::DELETE_USERS => 'Can delete users',

            self::VIEW_ROLES => 'Can view roles and permissions',
            self::CREATE_ROLES => 'Can create new roles',
            self::EDIT_ROLES => 'Can edit existing roles',
            self::DELETE_ROLES => 'Can delete roles',

            self::VIEW_PRODUCTS => 'Can view product inventory',
            self::CREATE_PRODUCTS => 'Can add new products',
            self::EDIT_PRODUCTS => 'Can modify product details',
            self::DELETE_PRODUCTS => 'Can remove products',
            self::MANAGE_CATEGORIES => 'Can create and manage product categories',
            self::MANAGE_LOCATIONS => 'Can create and manage storage locations',

            self::VIEW_ORDERS => 'Can view orders',
            self::CREATE_ORDERS => 'Can create new orders',
            self::EDIT_ORDERS => 'Can modify orders',
            self::DELETE_ORDERS => 'Can delete orders',
            self::APPROVE_ORDERS => 'Can approve or reject orders',

            self::VIEW_SETTINGS => 'Can view system settings',
            self::EDIT_SETTINGS => 'Can modify system settings',
            self::MANAGE_ORGANIZATION => 'Can manage organization details',

            self::VIEW_PLUGINS => 'Can view installed plugins',
            self::MANAGE_PLUGINS => 'Can install, activate, and delete plugins',

            self::VIEW_REPORTS => 'Can view system reports',
            self::EXPORT_DATA => 'Can export data from the system',
            self::IMPORT_DATA => 'Can import data into the system',
            self::VIEW_ACTIVITY_LOG => 'Can view activity and audit logs',
        };
    }

    /**
     * Get permission category
     */
    public function category(): string
    {
        return match($this) {
            self::VIEW_USERS, self::CREATE_USERS, self::EDIT_USERS, self::DELETE_USERS => 'User Management',
            self::VIEW_ROLES, self::CREATE_ROLES, self::EDIT_ROLES, self::DELETE_ROLES => 'Role Management',
            self::VIEW_PRODUCTS, self::CREATE_PRODUCTS, self::EDIT_PRODUCTS, self::DELETE_PRODUCTS,
            self::MANAGE_CATEGORIES, self::MANAGE_LOCATIONS => 'Inventory Management',
            self::VIEW_ORDERS, self::CREATE_ORDERS, self::EDIT_ORDERS, self::DELETE_ORDERS,
            self::APPROVE_ORDERS => 'Order Management',
            self::VIEW_SETTINGS, self::EDIT_SETTINGS, self::MANAGE_ORGANIZATION => 'Settings',
            self::VIEW_PLUGINS, self::MANAGE_PLUGINS => 'Plugins',
            self::VIEW_REPORTS, self::EXPORT_DATA, self::IMPORT_DATA, self::VIEW_ACTIVITY_LOG => 'Reports & Data',
        };
    }

    /**
     * Get all permissions grouped by category
     */
    public static function grouped(): array
    {
        $grouped = [];
        foreach (self::cases() as $permission) {
            $category = $permission->category();
            $grouped[$category][] = [
                'value' => $permission->value,
                'label' => $permission->label(),
                'description' => $permission->description(),
            ];
        }
        return $grouped;
    }
}
