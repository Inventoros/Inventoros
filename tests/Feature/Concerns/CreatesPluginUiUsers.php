<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;

/**
 * An admin, and a member who holds only view_products and view_orders (the
 * shape of the shipped Warehouse Staff template: no view_reports).
 */
trait CreatesPluginUiUsers
{
    protected User $admin;

    protected User $staff;

    protected function createPluginUiUsers(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $organization = Organization::create([
            'name' => 'Org', 'email' => 'org@example.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
            'organization_id' => $organization->id, 'role' => 'admin',
        ]);

        $this->staff = User::create([
            'name' => 'Staff', 'email' => 'staff@example.com', 'password' => bcrypt('password'),
            'organization_id' => $organization->id, 'role' => 'member',
        ]);

        $memberRole = Role::firstOrCreate(['slug' => 'system-member'], [
            'name' => 'Member', 'is_system' => true, 'permissions' => ['view_products', 'view_orders'],
        ]);
        $this->staff->roles()->syncWithoutDetaching([$memberRole->id]);
    }
}
