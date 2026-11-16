<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Organizations\OrganizationMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Deleting your own account from the profile page follows the same rule as
 * deleting a user from the Users screen: an organization is never left
 * without an administrator.
 */
class ProfileSelfDeletionLastAdminTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create(['name' => 'Org', 'email' => 'org@example.com']);
    }

    private function user(string $email, string $role, ?Organization $organization = null): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => ($organization ?? $this->organization)->id,
            'role' => $role,
        ]);
    }

    public function test_the_last_administrator_cannot_delete_their_own_account(): void
    {
        $admin = $this->user('admin@example.com', 'admin');
        $this->user('member@example.com', 'member');

        $this->actingAs($admin)
            ->from('/profile')
            ->delete('/profile', ['password' => 'password'])
            ->assertRedirect('/profile')
            ->assertSessionHasErrors('password');

        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull($admin->fresh());
    }

    public function test_the_last_administrator_of_another_organization_they_belong_to_cannot_delete_their_own_account(): void
    {
        $home = $this->user('home-admin@example.com', 'admin');
        $this->user('second-admin@example.com', 'admin');

        $other = Organization::create(['name' => 'Other', 'email' => 'other@example.com', 'is_active' => true]);
        app(OrganizationMembershipService::class)->add($other, $home, 'admin');

        $this->actingAs($home)
            ->from('/profile')
            ->delete('/profile', ['password' => 'password'])
            ->assertRedirect('/profile')
            ->assertSessionHasErrors('password');

        $this->assertNotNull($home->fresh());
    }

    public function test_an_administrator_can_delete_their_account_when_another_administrator_remains(): void
    {
        $admin = $this->user('admin@example.com', 'admin');
        $other = $this->user('other-admin@example.com', 'admin');

        $this->actingAs($admin)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($admin->fresh());
        $this->assertNotNull($other->fresh());
    }

    public function test_a_member_can_delete_their_account_while_the_only_administrator_remains(): void
    {
        $admin = $this->user('admin@example.com', 'admin');
        $member = $this->user('member@example.com', 'member');

        $this->actingAs($member)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($member->fresh());
        $this->assertNotNull($admin->fresh());
    }

    public function test_an_administrator_of_another_tenant_does_not_count_towards_this_organization(): void
    {
        $admin = $this->user('admin@example.com', 'admin');

        $otherTenant = Organization::create(['name' => 'Tenant B', 'email' => 'b@example.com']);
        $this->user('b-admin@example.com', 'admin', $otherTenant);

        $this->actingAs($admin)
            ->from('/profile')
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($admin->fresh());
    }
}
