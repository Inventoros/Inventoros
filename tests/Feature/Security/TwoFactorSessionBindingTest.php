<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The "two-factor verified" session marker must belong to the user who
 * passed the challenge. A marker left in a session by another account (or
 * by an earlier sign-in) must never let a different user skip the
 * challenge.
 */
class TwoFactorSessionBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $victim;

    private User $other;

    private string $secret;

    private array $recoveryCodes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $victimOrg = Organization::create(['name' => 'Victim Org', 'email' => 'victim-org@example.com']);
        $otherOrg = Organization::create(['name' => 'Other Org', 'email' => 'other-org@example.com']);

        $this->victim = User::create([
            'name' => 'Victim',
            'email' => 'victim@example.com',
            'password' => Hash::make('password'),
            'organization_id' => $victimOrg->id,
            'role' => 'admin',
        ]);

        $this->other = User::create([
            'name' => 'Other',
            'email' => 'other@example.com',
            'password' => Hash::make('password'),
            'organization_id' => $otherOrg->id,
            'role' => 'member',
        ]);

        $this->secret = (new Google2FA)->generateSecretKey();
        for ($i = 0; $i < 8; $i++) {
            $this->recoveryCodes[] = Str::random(10);
        }

        $this->victim->update([
            'two_factor_secret' => encrypt($this->secret),
            'two_factor_enabled' => true,
            'two_factor_recovery_codes' => encrypt(json_encode($this->recoveryCodes)),
        ]);
    }

    public function test_a_leftover_verified_marker_does_not_skip_the_challenge_after_password_login(): void
    {
        $this->withSession(['two_factor_verified' => true])
            ->post('/login', ['email' => 'victim@example.com', 'password' => 'password']);

        $this->assertAuthenticatedAs($this->victim);

        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));
    }

    public function test_a_marker_from_another_account_does_not_skip_the_challenge_after_password_login(): void
    {
        $this->withSession(['two_factor_verified' => $this->other->getKey()])
            ->post('/login', ['email' => 'victim@example.com', 'password' => 'password']);

        $this->assertAuthenticatedAs($this->victim);

        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));
    }

    public function test_a_marker_bound_to_another_user_is_not_accepted_by_the_middleware(): void
    {
        $this->actingAs($this->victim)
            ->withSession(['two_factor_verified' => $this->other->getKey()])
            ->get(route('dashboard'))
            ->assertRedirect(route('two-factor.challenge'));
    }

    public function test_a_bare_boolean_marker_is_not_accepted_by_the_middleware(): void
    {
        $this->actingAs($this->victim)
            ->withSession(['two_factor_verified' => true])
            ->get(route('dashboard'))
            ->assertRedirect(route('two-factor.challenge'));
    }

    public function test_password_login_clears_a_marker_bound_to_the_same_user(): void
    {
        // A fresh sign-in always starts with the second factor unverified.
        $this->withSession(['two_factor_verified' => $this->victim->getKey()])
            ->post('/login', ['email' => 'victim@example.com', 'password' => 'password']);

        $this->assertAuthenticatedAs($this->victim);
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));
    }

    public function test_a_valid_totp_challenge_binds_the_marker_to_the_user_and_rotates_the_session_id(): void
    {
        $sessionId = Str::random(40);

        $this->actingAs($this->victim)
            ->withCookie(config('session.cookie'), $sessionId)
            ->post(route('two-factor.challenge.verify'), ['code' => (new Google2FA)->getCurrentOtp($this->secret)])
            ->assertRedirect(route('dashboard'));

        $this->assertSame($this->victim->getKey(), session('two_factor_verified'));

        $this->assertNotSame($sessionId, session()->getId());

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_a_valid_recovery_code_binds_the_marker_to_the_user_and_rotates_the_session_id(): void
    {
        $sessionId = Str::random(40);

        $this->actingAs($this->victim)
            ->withCookie(config('session.cookie'), $sessionId)
            ->post(route('two-factor.challenge.verify'), ['recovery_code' => $this->recoveryCodes[0]])
            ->assertRedirect(route('dashboard'));

        $this->assertSame($this->victim->getKey(), session('two_factor_verified'));

        $this->assertNotSame($sessionId, session()->getId());

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_a_failed_challenge_does_not_mark_the_session_verified(): void
    {
        $this->actingAs($this->victim)
            ->post(route('two-factor.challenge.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code')
            ->assertSessionMissing('two_factor_verified');
    }

    public function test_full_sign_in_with_password_then_challenge_reaches_the_dashboard(): void
    {
        $this->post('/login', ['email' => 'victim@example.com', 'password' => 'password']);
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));

        $this->post(route('two-factor.challenge.verify'), ['code' => (new Google2FA)->getCurrentOtp($this->secret)])
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_enabling_two_factor_binds_the_marker_to_the_user(): void
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        $this->actingAs($this->other)
            ->withSession(['two_factor_secret' => $secret])
            ->post(route('two-factor.enable'), ['code' => $google2fa->getCurrentOtp($secret)])
            ->assertRedirect(route('settings.account.index'));

        $this->assertSame($this->other->getKey(), session('two_factor_verified'));

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_a_verified_user_can_still_disable_two_factor(): void
    {
        $this->actingAs($this->victim)
            ->withSession(['two_factor_verified' => $this->victim->getKey()])
            ->post(route('two-factor.disable'), ['password' => 'password'])
            ->assertRedirect(route('settings.account.index'))
            ->assertSessionMissing('two_factor_verified');

        $this->assertFalse($this->victim->fresh()->two_factor_enabled);
    }
}
