<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create system-wide default roles (no organization)
        // These serve as templates that can be copied for new organizations

        // Administrator Role - Full system access
        Role::updateOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'description' => 'Full system access with all permissions.',
                'organization_id' => null,
                'is_system' => true,
                'permissions' => collect(Permission::cases())->map(fn($p) => $p->value)->toArray(),
            ]
        );

        // Manager Role - Can manage inventory, orders, and view reports
        Role::updateOrCreate(
            ['slug' => 'system-manager'],
            [
                'name' => 'Manager',
                'description' => 'Can manage inventory, orders, categories, and view reports.',
                'organization_id' => null,
                'is_system' => true,
                'permissions' => [
                    Permission::VIEW_PRODUCTS->value,
                    Permission::CREATE_PRODUCTS->value,
                    Permission::EDIT_PRODUCTS->value,
                    Permission::DELETE_PRODUCTS->value,
                    Permission::MANAGE_CATEGORIES->value,
                    Permission::MANAGE_LOCATIONS->value,

                    Permission::VIEW_ORDERS->value,
                    Permission::CREATE_ORDERS->value,
                    Permission::EDIT_ORDERS->value,
                    Permission::DELETE_ORDERS->value,
                    Permission::APPROVE_ORDERS->value,

                    Permission::VIEW_REPORTS->value,
                    Permission::EXPORT_DATA->value,

                    Permission::VIEW_SETTINGS->value,
                ],
            ]
        );

        // Staff Role - Basic inventory and order management
        Role::updateOrCreate(
            ['slug' => 'system-staff'],
            [
                'name' => 'Staff',
                'description' => 'Can view and manage inventory and orders.',
                'organization_id' => null,
                'is_system' => true,
                'permissions' => [
                    Permission::VIEW_PRODUCTS->value,
                    Permission::CREATE_PRODUCTS->value,
                    Permission::EDIT_PRODUCTS->value,

                    Permission::VIEW_ORDERS->value,
                    Permission::CREATE_ORDERS->value,
                    Permission::EDIT_ORDERS->value,
                ],
            ]
        );

        // Viewer Role - Read-only access
        Role::updateOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'description' => 'Read-only access to inventory and orders.',
                'organization_id' => null,
                'is_system' => true,
                'permissions' => [
                    Permission::VIEW_PRODUCTS->value,
                    Permission::VIEW_ORDERS->value,
                    Permission::VIEW_REPORTS->value,
                ],
            ]
        );

        // Warehouse Role - Focused on inventory management
        Role::updateOrCreate(
            ['slug' => 'system-warehouse'],
            [
                'name' => 'Warehouse',
                'description' => 'Focused on inventory and location management.',
                'organization_id' => null,
                'is_system' => true,
                'permissions' => [
                    Permission::VIEW_PRODUCTS->value,
                    Permission::CREATE_PRODUCTS->value,
                    Permission::EDIT_PRODUCTS->value,
                    Permission::MANAGE_CATEGORIES->value,
                    Permission::MANAGE_LOCATIONS->value,

                    Permission::VIEW_ORDERS->value,
                ],
            ]
        );

        // Sales Role - Focused on orders
        Role::updateOrCreate(
            ['slug' => 'system-sales'],
            [
                'name' => 'Sales',
                'description' => 'Focused on order management and customer interactions.',
                'organization_id' => null,
                'is_system' => true,
                'permissions' => [
                    Permission::VIEW_PRODUCTS->value,

                    Permission::VIEW_ORDERS->value,
                    Permission::CREATE_ORDERS->value,
                    Permission::EDIT_ORDERS->value,

                    Permission::VIEW_REPORTS->value,
                ],
            ]
        );

        $this->command->info('Default system roles created successfully.');
    }
}
