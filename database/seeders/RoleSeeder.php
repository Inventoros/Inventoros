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
        // Create 3 base system roles matching the base user roles
        // These are system-wide and cannot be deleted

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

        // Member Role - Basic access
        Role::updateOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'description' => 'Basic access to view and manage assigned tasks.',
                'organization_id' => null,
                'is_system' => true,
                'permissions' => [
                    Permission::VIEW_PRODUCTS->value,
                    Permission::VIEW_ORDERS->value,
                ],
            ]
        );

        $this->command->info('3 base system roles created successfully.');
    }
}
