<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Models\Auth\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organizations\Concerns\BuildsOrganizations;
use Tests\TestCase;

/**
 * The home organization owns the account, but its administrators and user
 * managers must not be able to take over an account that also works in other
 * organizations: resetting its password or email, or deleting it, would hand
 * them (or take away) access to organizations they do not run.
 */
final class CrossOrganizationAccountTest extends TestCase
{
    use BuildsOrganizations, RefreshDatabase;

    private Organization $beta;

    private Organization $gamma;

    /** Home in Beta, administrator of Gamma. */
    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();

        $this->beta = $this->organization('Beta');
        $this->gamma = $this->organization('Gamma');
        $this->homeUser($this->gamma, 'admin', 'GammaOwner');
        $this->target = $this->homeUser($this->beta, 'member', 'Target');
        $this->addMember($this->gamma, $this->target, 'admin');
    }

    private function userManager(): User
    {
        $manager = $this->homeUser($this->beta, 'member', 'Delegate');
        $this->grantInOrganization($manager, $this->beta, ['view_users', 'edit_users', 'delete_users']);

        return $manager;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Target',
            'email' => $this->target->email,
            'role' => 'member',
            'password' => 'TakenOver-123!',
            'password_confirmation' => 'TakenOver-123!',
        ], $overrides);
    }

    private function assertPasswordUnchanged(): void
    {
        $this->assertTrue(Hash::check('password', $this->target->fresh()->password), 'The password was changed.');
    }

    public function test_a_user_manager_cannot_take_over_an_account_that_is_admin_elsewhere(): void
    {
        $this->actingAs($this->userManager())
            ->put(route('users.update', $this->target), $this->payload())
            ->assertForbidden();

        $this->assertPasswordUnchanged();

        // And so cannot sign in over the API as the target in Gamma.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/login', ['email' => $this->target->email, 'password' => 'TakenOver-123!', 'organization_id' => $this->gamma->id])
            ->assertUnprocessable();
    }

    public function test_a_user_manager_cannot_take_over_that_account_over_the_api_either(): void
    {
        Sanctum::actingAs($this->userManager(), ['*']);

        $this->putJson('/api/v1/users/'.$this->target->id, $this->payload())->assertForbidden();

        $this->assertPasswordUnchanged();
    }

    public function test_a_user_manager_cannot_manage_someone_holding_permissions_elsewhere_they_lack(): void
    {
        $plain = $this->homeUser($this->beta, 'member', 'Plain');
        $this->addMember($this->gamma, $plain, 'member');
        $this->grantInOrganization($plain, $this->gamma, ['delete_products']);

        $this->actingAs($this->userManager())
            ->put(route('users.update', $plain), ['name' => 'Renamed', 'email' => $plain->email, 'role' => 'member'])
            ->assertForbidden();

        $this->assertSame('Plain', $plain->fresh()->name);
    }

    public function test_a_user_manager_still_manages_plain_home_users(): void
    {
        $plain = $this->homeUser($this->beta, 'member', 'Plain');

        $this->actingAs($this->userManager())
            ->put(route('users.update', $plain), ['name' => 'Renamed', 'email' => $plain->email, 'role' => 'member'])
            ->assertRedirect(route('users.index'));

        $this->assertSame('Renamed', $plain->fresh()->name);
    }

    public function test_a_home_administrator_cannot_reset_the_credentials_of_someone_working_elsewhere(): void
    {
        $admin = $this->homeUser($this->beta, 'admin', 'BetaAdmin');

        $this->actingAs($admin)->put(route('users.update', $this->target), $this->payload())->assertForbidden();
        $this->assertPasswordUnchanged();

        $this->actingAs($admin)
            ->put(route('users.update', $this->target), $this->payload(['password' => null, 'password_confirmation' => null, 'email' => 'hijack@evil.test']))
            ->assertForbidden();
        $this->assertSame($this->target->email, $this->target->fresh()->email);

        // A plain outside membership is enough: the account reaches Gamma's data.
        $plain = $this->homeUser($this->beta, 'member', 'Plain');
        $this->addMember($this->gamma, $plain, 'member');
        $this->actingAs($admin)
            ->put(route('users.update', $plain), ['name' => 'Plain', 'email' => $plain->email, 'role' => 'member', 'password' => 'TakenOver-123!', 'password_confirmation' => 'TakenOver-123!'])
            ->assertForbidden();
    }

    public function test_a_home_administrator_still_edits_the_name_and_home_role_of_someone_working_elsewhere(): void
    {
        $admin = $this->homeUser($this->beta, 'admin', 'BetaAdmin');

        $this->actingAs($admin)
            ->put(route('users.update', $this->target), $this->payload(['name' => 'Renamed', 'role' => 'manager', 'password' => null, 'password_confirmation' => null]))
            ->assertRedirect(route('users.index'));

        $this->assertSame(['Renamed', 'manager'], [$this->target->fresh()->name, $this->target->fresh()->role]);
        $this->assertPasswordUnchanged();
    }

    public function test_an_administrator_of_every_organization_the_user_works_in_may_reset_their_password(): void
    {
        $admin = $this->homeUser($this->beta, 'admin', 'BetaAdmin');
        $this->addMember($this->gamma, $admin, 'admin');

        $this->actingAs($admin)->put(route('users.update', $this->target), $this->payload())->assertRedirect(route('users.index'));

        $this->assertTrue(Hash::check('TakenOver-123!', $this->target->fresh()->password));
    }

    public function test_a_home_administrator_cannot_delete_someone_working_elsewhere(): void
    {
        $admin = $this->homeUser($this->beta, 'admin', 'BetaAdmin');

        $this->actingAs($admin)->delete(route('users.destroy', $this->target))->assertForbidden();

        $this->assertNotNull($this->target->fresh());
    }

    public function test_the_last_administrator_of_another_organization_is_never_deleted(): void
    {
        // Gamma's own administrator leaves; Target is now Gamma's only one.
        $lone = $this->organization('Delta');
        $last = $this->homeUser($this->beta, 'member', 'Last');
        $this->addMember($lone, $last, 'admin');

        $admin = $this->homeUser($this->beta, 'admin', 'BetaAdmin');
        $this->addMember($lone, $admin, 'manager');

        $this->actingAs($admin)->delete(route('users.destroy', $last))->assertForbidden();

        $this->assertNotNull($last->fresh());
    }
}
