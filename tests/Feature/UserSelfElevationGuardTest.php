<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class UserSelfElevationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $org = Organization::create(['name' => 'O', 'email' => 'o@test.com']);
        $this->user = User::create([
            'name' => 'Member',
            'email' => 'member@test.com',
            'password' => bcrypt('x'),
            'organization_id' => $org->id,
            'role' => 'member',
        ]);
    }

    public function test_authenticated_user_cannot_elevate_their_own_role(): void
    {
        $this->actingAs($this->user);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Users cannot change their own role.');

        $this->user->update(['role' => 'admin']);
    }

    public function test_authenticated_user_cannot_move_themselves_to_another_organization(): void
    {
        $otherOrg = Organization::create(['name' => 'Other', 'email' => 'other@test.com']);

        $this->actingAs($this->user);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Users cannot change their own organization.');

        $this->user->update(['organization_id' => $otherOrg->id]);
    }

    public function test_other_admin_can_still_change_a_users_role(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('x'),
            'organization_id' => $this->user->organization_id,
            'role' => 'admin',
        ]);

        $this->actingAs($admin);

        // Admin updating someone else's role — not self — should still work.
        $this->user->update(['role' => 'manager']);

        $this->assertSame('manager', $this->user->fresh()->role);
    }

    public function test_unauthenticated_context_can_still_set_role_and_org(): void
    {
        // Tests and console commands run without auth(); the guard must
        // not break those flows. (No actingAs here.)
        $this->user->update(['role' => 'manager']);

        $this->assertSame('manager', $this->user->fresh()->role);
    }
}
