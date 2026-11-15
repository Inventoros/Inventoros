<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Changes that would let someone holding a session keep the account
 * (changing the sign-in email, enrolling a second factor, minting an API
 * token) need the current password.
 */
class SensitiveAccountChangeReauthenticationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $organization = Organization::create(['name' => 'Org', 'email' => 'org@example.com']);
        $this->user = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
            'organization_id' => $organization->id,
            'role' => 'admin',
        ]);
        $this->user->forceFill(['email_verified_at' => now()])->save();
    }

    // ── Email change (Settings > Account) ────────────────────────────

    public function test_account_settings_email_change_without_the_current_password_is_refused(): void
    {
        $this->actingAs($this->user)
            ->patch(route('settings.account.update.profile'), ['name' => 'Owner', 'email' => 'new@example.com'])
            ->assertSessionHasErrors('current_password');

        $this->assertSame('owner@example.com', $this->user->fresh()->email);
    }

    public function test_account_settings_email_change_with_a_wrong_password_is_refused(): void
    {
        $this->actingAs($this->user)
            ->patch(route('settings.account.update.profile'), [
                'name' => 'Owner', 'email' => 'new@example.com', 'current_password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame('owner@example.com', $this->user->fresh()->email);
    }

    public function test_account_settings_email_change_with_the_current_password_resets_verification_and_lowercases(): void
    {
        $this->actingAs($this->user)
            ->patch(route('settings.account.update.profile'), [
                'name' => 'Owner', 'email' => 'New.Address@Example.com', 'current_password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $this->user->fresh();
        $this->assertSame('new.address@example.com', $fresh->email);
        $this->assertNull($fresh->email_verified_at);
    }

    public function test_account_settings_name_change_needs_no_password_and_keeps_verification(): void
    {
        // Same address in a different case is not an email change.
        $this->actingAs($this->user)
            ->patch(route('settings.account.update.profile'), ['name' => 'Renamed', 'email' => 'Owner@Example.com'])
            ->assertSessionHasNoErrors();

        $fresh = $this->user->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('owner@example.com', $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function test_account_settings_email_change_cannot_take_another_users_address(): void
    {
        User::create([
            'name' => 'Other', 'email' => 'taken@example.com', 'password' => Hash::make('password'),
            'organization_id' => $this->user->organization_id, 'role' => 'member',
        ]);

        $this->actingAs($this->user)
            ->patch(route('settings.account.update.profile'), [
                'name' => 'Owner', 'email' => 'Taken@Example.com', 'current_password' => 'password',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame('owner@example.com', $this->user->fresh()->email);
    }

    // ── Email change (Profile) ────────────────────────────────────────

    public function test_profile_email_change_without_the_current_password_is_refused(): void
    {
        $this->actingAs($this->user)
            ->patch(route('profile.update'), ['name' => 'Owner', 'email' => 'new@example.com'])
            ->assertSessionHasErrors('current_password');

        $this->assertSame('owner@example.com', $this->user->fresh()->email);
    }

    public function test_profile_email_change_with_the_current_password_succeeds(): void
    {
        $this->actingAs($this->user)
            ->patch(route('profile.update'), ['name' => 'Owner', 'email' => 'new@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertSame('new@example.com', $this->user->fresh()->email);
        $this->assertNull($this->user->fresh()->email_verified_at);
    }

    public function test_profile_name_change_needs_no_password(): void
    {
        $this->actingAs($this->user)
            ->patch(route('profile.update'), ['name' => 'Renamed', 'email' => 'owner@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $this->user->fresh()->name);
    }

    // ── Enabling two-factor ───────────────────────────────────────────

    private function enable(array $extra): \Illuminate\Testing\TestResponse
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        return $this->actingAs($this->user)
            ->withSession(['two_factor_secret' => $secret])
            ->post(route('two-factor.enable'), array_merge(['code' => $google2fa->getCurrentOtp($secret)], $extra));
    }

    public function test_enabling_two_factor_without_the_current_password_is_refused(): void
    {
        $this->enable([])->assertSessionHasErrors('current_password');

        $this->assertFalse($this->user->fresh()->two_factor_enabled);
    }

    public function test_enabling_two_factor_with_a_wrong_password_is_refused(): void
    {
        $this->enable(['current_password' => 'wrong-password'])->assertSessionHasErrors('current_password');

        $this->assertFalse($this->user->fresh()->two_factor_enabled);
    }

    public function test_enabling_two_factor_with_the_current_password_succeeds(): void
    {
        $this->enable(['current_password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.account.index'));

        $this->assertTrue($this->user->fresh()->two_factor_enabled);
    }

    // ── API token creation (Settings > API tokens) ────────────────────

    private function tokenCount(): int
    {
        return PersonalAccessToken::query()->where('tokenable_id', $this->user->id)->count();
    }

    public function test_creating_an_api_token_without_the_current_password_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.api-tokens.store'), ['name' => 'ci', 'abilities' => ['view_products']])
            ->assertSessionHasErrors('current_password');

        $this->assertSame(0, $this->tokenCount());
    }

    public function test_creating_an_api_token_with_a_wrong_password_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.api-tokens.store'), [
                'name' => 'ci', 'abilities' => ['view_products'], 'current_password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame(0, $this->tokenCount());
    }

    public function test_creating_an_api_token_with_the_current_password_succeeds(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.api-tokens.store'), [
                'name' => 'ci', 'abilities' => ['view_products'], 'current_password' => 'password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.api-tokens.index'))
            ->assertSessionHas('newApiToken');

        $this->assertSame(1, $this->tokenCount());
    }
}
