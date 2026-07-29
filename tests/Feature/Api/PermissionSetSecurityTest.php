<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Permission-set creation must not be an escalation primitive: the permission
 * strings are allowlisted against the enum, and a non-admin can only include
 * permissions they themselves hold (sets merge into effective role permissions).
 */
class PermissionSetSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $delegate;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@o.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        // A non-admin who can manage roles but only holds create_roles +
        // view_products (no system-member role exists, so no base permissions).
        $this->delegate = User::create([
            'name' => 'Delegate', 'email' => 'delegate@o.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $role = Role::create([
            'slug' => 'role-manager', 'name' => 'Role Manager', 'is_system' => false,
            'organization_id' => $this->org->id,
            'permissions' => ['create_roles', 'edit_roles', 'view_products'],
        ]);
        $this->delegate->roles()->syncWithoutDetaching([$role->id]);
    }

    private function postSet(array $permissions)
    {
        return $this->postJson('/api/v1/permission-sets', [
            'name' => 'Set', 'permissions' => $permissions,
        ]);
    }

    public function test_non_admin_cannot_include_a_permission_they_do_not_hold(): void
    {
        Sanctum::actingAs($this->delegate);

        // delete_users is a real permission, but the delegate doesn't hold it.
        $this->postSet(['view_products', 'delete_users'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');

        $this->assertDatabaseMissing('permission_sets', ['name' => 'Set']);
    }

    public function test_non_admin_can_build_a_set_from_permissions_they_hold(): void
    {
        Sanctum::actingAs($this->delegate);

        $this->postSet(['view_products'])->assertStatus(201);
        $this->assertDatabaseHas('permission_sets', ['name' => 'Set', 'organization_id' => $this->org->id]);
    }

    public function test_arbitrary_permission_strings_are_rejected(): void
    {
        Sanctum::actingAs($this->delegate);

        $this->postSet(['not_a_real_permission'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_admin_may_include_any_valid_permission(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postSet(['delete_users', 'manage_organization'])->assertStatus(201);
    }
}
