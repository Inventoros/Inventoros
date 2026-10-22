<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\UserManagementService;
use App\Support\RoleAssignmentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Portal\BuildsPortalFixtures;
use Tests\TestCase;

final class TenantRoleAssignmentIsolationTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    private function fixtures(): array
    {
        $this->markInstalled();
        $org = $this->makeOrganization('Role owner');
        $outside = $this->makeOrganization('Other organization');
        $admin = $this->makeStaff($org, 'admin@roles.test');
        $foreign = Role::create(['organization_id' => $outside->id, 'name' => 'Confidential role', 'slug' => 'confidential-role', 'permissions' => ['view_products']]);

        return [$org, $admin, $foreign];
    }

    private function payload(array $roles): array
    {
        return ['name' => 'New member', 'email' => 'new@roles.test', 'password' => 'Password-2026!',
            'password_confirmation' => 'Password-2026!', 'role' => 'member', 'role_ids' => $roles];
    }

    public function test_admin_cannot_create_a_user_with_another_tenants_role(): void
    {
        [$org, $admin, $foreign] = $this->fixtures();
        $this->actingAs($admin)->post(route('users.store'), $this->payload([$foreign->id]))
            ->assertSessionHasErrors('role_ids.0');
        $this->assertDatabaseMissing('users', ['email' => 'new@roles.test']);
    }

    public function test_admin_cannot_update_a_user_with_another_tenants_role(): void
    {
        [$org, $admin, $foreign] = $this->fixtures();
        $target = $this->makeStaff($org, 'target@roles.test', 'member');
        $originalName = $target->name;
        $this->actingAs($admin)->put(route('users.update', $target), array_merge($this->payload([$foreign->id]), ['email' => $target->email]))
            ->assertSessionHasErrors('role_ids.0');
        $this->assertSame($originalName, $target->fresh()->name);
        $this->assertSame(0, $target->roles()->count());
    }

    public function test_shared_assignment_guard_also_rejects_foreign_roles_for_admins(): void
    {
        [$org, $admin, $foreign] = $this->fixtures();
        $this->assertSame('You cannot assign a role from another organization.', RoleAssignmentGuard::violation([$foreign->id], $admin, 'admin'));
    }

    public function test_direct_service_cannot_bypass_tenant_role_isolation(): void
    {
        [$org, $admin, $foreign] = $this->fixtures();
        try {
            app(UserManagementService::class)->create($admin, $this->payload([$foreign->id]));
            $this->fail('Foreign role was accepted by the shared service.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseMissing('users', ['email' => 'new@roles.test']);
    }

    public function test_admin_can_still_assign_own_and_shared_system_roles(): void
    {
        [$org, $admin] = $this->fixtures();
        $own = Role::create(['organization_id' => $org->id, 'name' => 'Own role', 'slug' => 'own-role', 'permissions' => ['view_products']]);
        $system = Role::firstOrCreate(['slug' => 'system-member'], ['name' => 'Member', 'is_system' => true, 'organization_id' => null, 'permissions' => []]);
        $this->actingAs($admin)->post(route('users.store'), $this->payload([$own->id, $system->id]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('email', 'new@roles.test')->firstOrFail();
        $this->assertEqualsCanonicalizing([$own->id, $system->id], $user->roles()->pluck('roles.id')->all());
        $this->assertNull(RoleAssignmentGuard::violation([$own->id, $system->id], $admin, 'admin'));
    }

    public function test_missing_role_id_is_rejected_even_when_allowed_roles_exist(): void
    {
        [$org, $admin] = $this->fixtures();
        Role::create(['organization_id' => $org->id, 'name' => 'Own role', 'slug' => 'own-role']);
        Role::create(['organization_id' => null, 'name' => 'Shared role', 'slug' => 'shared-role', 'is_system' => true]);
        $this->actingAs($admin)->post(route('users.store'), $this->payload([999999]))
            ->assertSessionHasErrors('role_ids.0');
        $this->assertDatabaseMissing('users', ['email' => 'new@roles.test']);
    }
}
