<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\PermissionSet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionSetEffectivePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_granted_through_a_permission_set_are_listed(): void
    {
        $org = Organization::create([
            'name' => 'Sets Org', 'email' => 'sets@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $user = User::create([
            'name' => 'Member', 'email' => 'member@sets.com', 'password' => bcrypt('password'),
            'organization_id' => $org->id,
        ]);
        $user->forceFill(['role' => 'member'])->save();

        // A custom role whose permissions come only from a permission set.
        $set = PermissionSet::create([
            'organization_id' => $org->id, 'name' => 'Org admin', 'slug' => 'org-admin',
            'permissions' => ['manage_organization'], 'is_active' => true,
        ]);
        $role = Role::create([
            'slug' => 'set-only', 'name' => 'Set only', 'is_system' => false,
            'organization_id' => $org->id, 'permissions' => [],
        ]);
        $role->permissionSets()->attach($set->id);
        $user->roles()->syncWithoutDetaching([$role->id]);

        $user = $user->fresh();
        $this->assertTrue($user->hasPermission('manage_organization'));
        $this->assertContains('manage_organization', $user->getAllPermissions());
    }

    public function test_an_inactive_permission_set_grants_nothing(): void
    {
        $org = Organization::create([
            'name' => 'Sets Org 2', 'email' => 'sets2@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $user = User::create([
            'name' => 'Member', 'email' => 'member2@sets.com', 'password' => bcrypt('password'),
            'organization_id' => $org->id,
        ]);
        $user->forceFill(['role' => 'member'])->save();

        $set = PermissionSet::create([
            'organization_id' => $org->id, 'name' => 'Off', 'slug' => 'off',
            'permissions' => ['manage_organization'], 'is_active' => false,
        ]);
        $role = Role::create([
            'slug' => 'set-off', 'name' => 'Set off', 'is_system' => false,
            'organization_id' => $org->id, 'permissions' => [],
        ]);
        $role->permissionSets()->attach($set->id);
        $user->roles()->syncWithoutDetaching([$role->id]);

        $user = $user->fresh();
        $this->assertFalse($user->hasPermission('manage_organization'));
        $this->assertNotContains('manage_organization', $user->getAllPermissions());
    }
}
