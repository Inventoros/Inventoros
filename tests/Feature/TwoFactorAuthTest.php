<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);
    }

    // ── Setup Flow ─────────────────────────────────────────────────

    public function test_user_can_view_two_factor_setup_page(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('two-factor.setup'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Auth/TwoFactorSetup')
            ->has('qrCodeSvg')
            ->has('secret')
            ->has('recoveryCodes')
        );
    }

    public function test_guest_cannot_access_two_factor_setup(): void
    {
        $response = $this->get(route('two-factor.setup'));

        $response->assertRedirect(route('login'));
    }

    // ── Enable Flow ────────────────────────────────────────────────

    public function test_user_can_enable_two_factor_with_valid_code(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        // Store the secret in session as the setup page would
        $response = $this->actingAs($this->user)
            ->withSession(['two_factor_secret' => $secret])
            ->post(route('two-factor.enable'), [
                'code' => $google2fa->getCurrentOtp($secret),
            ]);

        $response->assertRedirect(route('settings.account.index'));

        $this->user->refresh();
        $this->assertTrue($this->user->two_factor_enabled);
        $this->assertNotNull($this->user->two_factor_secret);
        $this->assertNotNull($this->user->two_factor_recovery_codes);
    }

    public function test_user_cannot_enable_two_factor_with_invalid_code(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $response = $this->actingAs($this->user)
            ->withSession(['two_factor_secret' => $secret])
            ->post(route('two-factor.enable'), [
                'code' => '000000',
            ]);

        $response->assertSessionHasErrors('code');

        $this->user->refresh();
        $this->assertFalse($this->user->two_factor_enabled);
    }

    public function test_enable_requires_code_field(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['two_factor_secret' => 'TESTSECRET1234567'])
            ->post(route('two-factor.enable'), []);

        $response->assertSessionHasErrors('code');
    }

    // ── Disable Flow ───────────────────────────────────────────────

    public function test_user_can_disable_two_factor(): void
    {
        $this->enableTwoFactorForUser($this->user);

        $response = $this->actingAs($this->user)
            ->post(route('two-factor.disable'), [
                'password' => 'password',
            ]);

        $response->assertRedirect(route('settings.account.index'));

        $this->user->refresh();
        $this->assertFalse($this->user->two_factor_enabled);
        $this->assertNull($this->user->two_factor_secret);
        $this->assertNull($this->user->two_factor_recovery_codes);
    }

    public function test_user_cannot_disable_two_factor_with_wrong_password(): void
    {
        $this->enableTwoFactorForUser($this->user);

        $response = $this->actingAs($this->user)
            ->post(route('two-factor.disable'), [
                'password' => 'wrong-password',
            ]);

        $response->assertSessionHasErrors('password');

        $this->user->refresh();
        $this->assertTrue($this->user->two_factor_enabled);
    }

    // ── Challenge Verification ─────────────────────────────────────

    public function test_user_with_2fa_is_redirected_to_challenge_after_login(): void
    {
        $this->enableTwoFactorForUser($this->user);

        // Simulate login — the middleware should redirect to challenge
        $response = $this->actingAs($this->user)
            ->get(route('dashboard'));

        $response->assertRedirect(route('two-factor.challenge'));
    }

    public function test_user_without_2fa_is_not_redirected_to_challenge(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('dashboard'));

        $response->assertStatus(200);
    }

    public function test_user_can_view_challenge_page(): void
    {
        $this->enableTwoFactorForUser($this->user);

        $response = $this->actingAs($this->user)
            ->get(route('two-factor.challenge'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Auth/TwoFactorChallenge')
        );
    }

    public function test_user_can_verify_challenge_with_valid_code(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $this->enableTwoFactorForUser($this->user, $secret);

        $response = $this->actingAs($this->user)
            ->post(route('two-factor.challenge.verify'), [
                'code' => $google2fa->getCurrentOtp($secret),
            ]);

        $response->assertRedirect(route('dashboard'));
    }

    public function test_user_cannot_verify_challenge_with_invalid_code(): void
    {
        $this->enableTwoFactorForUser($this->user);

        $response = $this->actingAs($this->user)
            ->post(route('two-factor.challenge.verify'), [
                'code' => '000000',
            ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_verified_user_can_access_protected_routes(): void
    {
        $this->enableTwoFactorForUser($this->user);

        $response = $this->actingAs($this->user)
            ->withSession(['two_factor_verified' => true])
            ->get(route('dashboard'));

        $response->assertStatus(200);
    }

    // ── Recovery Codes ─────────────────────────────────────────────

    public function test_user_can_verify_challenge_with_recovery_code(): void
    {
        $this->enableTwoFactorForUser($this->user);

        // Get the stored recovery codes
        $recoveryCodes = json_decode(
            decrypt($this->user->two_factor_recovery_codes),
            true
        );
        $recoveryCode = $recoveryCodes[0];

        $response = $this->actingAs($this->user)
            ->post(route('two-factor.challenge.verify'), [
                'recovery_code' => $recoveryCode,
            ]);

        $response->assertRedirect(route('dashboard'));

        // Verify the used recovery code was removed
        $this->user->refresh();
        $updatedCodes = json_decode(
            decrypt($this->user->two_factor_recovery_codes),
            true
        );
        $this->assertNotContains($recoveryCode, $updatedCodes);
        $this->assertCount(7, $updatedCodes);
    }

    public function test_invalid_recovery_code_is_rejected(): void
    {
        $this->enableTwoFactorForUser($this->user);

        $response = $this->actingAs($this->user)
            ->post(route('two-factor.challenge.verify'), [
                'recovery_code' => 'invalid-recovery-code',
            ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_recovery_codes_are_generated_on_enable(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $this->actingAs($this->user)
            ->withSession(['two_factor_secret' => $secret])
            ->post(route('two-factor.enable'), [
                'code' => $google2fa->getCurrentOtp($secret),
            ]);

        $this->user->refresh();
        $recoveryCodes = json_decode(
            decrypt($this->user->two_factor_recovery_codes),
            true
        );

        $this->assertCount(8, $recoveryCodes);
        foreach ($recoveryCodes as $code) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{10}$/', $code);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────

    protected function enableTwoFactorForUser(User $user, ?string $secret = null): void
    {
        $google2fa = new Google2FA();
        $secret = $secret ?? $google2fa->generateSecretKey();

        $recoveryCodes = [];
        for ($i = 0; $i < 8; $i++) {
            $recoveryCodes[] = \Illuminate\Support\Str::random(10);
        }

        $user->update([
            'two_factor_secret' => encrypt($secret),
            'two_factor_enabled' => true,
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
        ]);
    }
}
