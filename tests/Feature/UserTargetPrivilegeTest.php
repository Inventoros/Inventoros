<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * A delegated user administrator (edit_users / delete_users, not an admin)
 * must not be able to change the credentials of, demote, or delete someone
 * who holds more privilege than they do. The role-assignment guard only
 * looked at the roles being assigned, never at the TARGET, so a "User
 * Administrator" could reset an admin's password and sign in as them.
 */
class UserTargetPrivilegeTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $secondAdmin;

    private User $userAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
        // A second admin so the "last administrator" rule cannot be what blocks the demotion.
        $this->secondAdmin = $this->makeAdmin($this->org);
        $this->userAdmin = $this->makeMember($this->org, ['view_users', 'create_users', 'edit_users', 'delete_users']);
    }

    private function takeoverPayload(User $victim): array
    {
        return [
            'name' => $victim->name,
            'email' => $victim->email,
            'role' => 'member',
            'password' => 'Hijack3d!Passw0rd#2026',
            'password_confirmation' => 'Hijack3d!Passw0rd#2026',
        ];
    }

    public function test_api_user_administrator_cannot_reset_an_admins_password(): void
    {
        Sanctum::actingAs($this->userAdmin);

        $this->putJson("/api/v1/users/{$this->secondAdmin->id}", $this->takeoverPayload($this->secondAdmin))
            ->assertForbidden();

        $fresh = $this->secondAdmin->fresh();
        $this->assertSame('admin', $fresh->role);
        $this->assertFalse(Hash::check('Hijack3d!Passw0rd#2026', $fresh->password));
    }

    public function test_api_user_administrator_cannot_change_an_admins_email(): void
    {
        Sanctum::actingAs($this->userAdmin);

        $this->putJson("/api/v1/users/{$this->secondAdmin->id}", [
            'name' => $this->secondAdmin->name,
            'email' => 'attacker@evil.test',
            'role' => 'member',
        ])->assertForbidden();

        $this->assertNotSame('attacker@evil.test', $this->secondAdmin->fresh()->email);
    }

    public function test_web_user_administrator_cannot_reset_an_admins_password(): void
    {
        $this->actingAs($this->userAdmin)
            ->put(route('users.update', $this->secondAdmin), $this->takeoverPayload($this->secondAdmin))
            ->assertForbidden();

        $this->assertFalse(Hash::check('Hijack3d!Passw0rd#2026', $this->secondAdmin->fresh()->password));
    }

    public function test_user_administrator_cannot_take_over_a_system_administrator_role_holder(): void
    {
        $systemAdminRole = Role::firstOrCreate(['slug' => 'system-administrator'], [
            'name' => 'Administrator', 'is_system' => true, 'permissions' => [],
        ]);
        $victim = $this->makeMember($this->org);
        $victim->roles()->attach($systemAdminRole->id);

        $this->actingAs($this->userAdmin)
            ->put(route('users.update', $victim), $this->takeoverPayload($victim))
            ->assertForbidden();
    }

    public function test_user_administrator_cannot_take_over_a_manager(): void
    {
        $victim = $this->makeMember($this->org);
        $victim->forceFill(['role' => 'manager'])->save();

        Sanctum::actingAs($this->userAdmin);
        $this->putJson("/api/v1/users/{$victim->id}", $this->takeoverPayload($victim))->assertForbidden();
        $this->assertSame('manager', $victim->fresh()->role);
    }

    public function test_user_administrator_cannot_take_over_someone_holding_permissions_they_lack(): void
    {
        $victim = $this->makeMember($this->org, ['view_users', 'manage_settings']);

        Sanctum::actingAs($this->userAdmin);
        $this->putJson("/api/v1/users/{$victim->id}", $this->takeoverPayload($victim))->assertForbidden();
        $this->assertFalse(Hash::check('Hijack3d!Passw0rd#2026', $victim->fresh()->password));
    }

    public function test_user_administrator_cannot_delete_an_admin(): void
    {
        $this->actingAs($this->userAdmin)
            ->delete(route('users.destroy', $this->secondAdmin))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $this->secondAdmin->id]);
    }

    public function test_user_administrator_can_still_manage_a_less_privileged_member(): void
    {
        $victim = $this->makeMember($this->org, ['view_users']);

        Sanctum::actingAs($this->userAdmin);
        $this->putJson("/api/v1/users/{$victim->id}", $this->takeoverPayload($victim))->assertOk();
        $this->assertTrue(Hash::check('Hijack3d!Passw0rd#2026', $victim->fresh()->password));

        $this->actingAs($this->userAdmin)
            ->delete(route('users.destroy', $victim))
            ->assertRedirect(route('users.index'));
        $this->assertDatabaseMissing('users', ['id' => $victim->id]);
    }

    public function test_admin_can_still_reset_another_admins_password(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/users/{$this->secondAdmin->id}", array_merge(
            $this->takeoverPayload($this->secondAdmin),
            ['role' => 'admin'],
        ))->assertOk();

        $this->assertTrue(Hash::check('Hijack3d!Passw0rd#2026', $this->secondAdmin->fresh()->password));
    }
}
