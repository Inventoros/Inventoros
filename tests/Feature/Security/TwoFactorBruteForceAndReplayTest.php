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
 * The second factor has a per-account failure budget shared by the web
 * challenge and the API login (independent of the caller's IP), and an
 * accepted TOTP code cannot be accepted a second time.
 */
class TwoFactorBruteForceAndReplayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    private string $secret;

    private Google2FA $google2fa;

    private int $ip = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $this->google2fa = new Google2FA;

        $organization = Organization::create(['name' => 'Org A', 'email' => 'org-a@example.com']);
        $otherOrganization = Organization::create(['name' => 'Org B', 'email' => 'org-b@example.com']);

        $this->user = $this->twoFactorUser('user@example.com', $organization, $this->secret = $this->google2fa->generateSecretKey());
        $this->otherUser = $this->twoFactorUser('other@example.com', $otherOrganization, $this->google2fa->generateSecretKey());
    }

    private function twoFactorUser(string $email, Organization $organization, string $secret): User
    {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password123'),
            'organization_id' => $organization->id,
            'role' => 'admin',
        ]);

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-aaaa-1111', 'recovery-bbbb-2222'])),
        ])->save();

        return $user;
    }

    /** API login from a new client IP each call, so the per-IP route throttle never applies. */
    private function apiLogin(array $payload, string $email = 'user@example.com')
    {
        $this->ip++;

        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$this->ip])
            ->postJson('/api/v1/login', array_merge(['email' => $email, 'password' => 'password123'], $payload));
    }

    private function codeAt(int $offset): string
    {
        return $this->google2fa->oathTotp($this->secret, $this->google2fa->getTimestamp() + $offset);
    }

    private function wrongCode(): string
    {
        $valid = [$this->codeAt(-1), $this->codeAt(0), $this->codeAt(1)];
        foreach (['000000', '111111', '222222', '333333'] as $candidate) {
            if (! in_array($candidate, $valid, true)) {
                return $candidate;
            }
        }

        return '444444';
    }

    private function tokenCount(User $user): int
    {
        return PersonalAccessToken::query()->where('tokenable_id', $user->id)->count();
    }

    // ── Per-account failure budget ────────────────────────────────────

    public function test_api_totp_guesses_from_many_ips_are_locked_out_per_account(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->apiLogin(['code' => $this->wrongCode()])->assertStatus(422)->assertJsonValidationErrors(['code']);
        }

        $this->apiLogin(['code' => $this->wrongCode()])->assertStatus(429);

        // Even the right code is refused while the account is locked out.
        $this->apiLogin(['code' => $this->codeAt(0)])->assertStatus(429);
        $this->assertSame(0, $this->tokenCount($this->user));
    }

    public function test_recovery_code_guesses_count_towards_the_same_budget_and_do_not_consume_codes_while_locked(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->apiLogin(['recovery_code' => 'wrong-'.$i])->assertStatus(422);
        }

        $this->apiLogin(['recovery_code' => 'recovery-aaaa-1111'])->assertStatus(429);

        $stored = json_decode(decrypt($this->user->fresh()->two_factor_recovery_codes), true);
        $this->assertCount(2, $stored);
        $this->assertSame(0, $this->tokenCount($this->user));
    }

    public function test_api_failures_lock_the_web_challenge_too(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->apiLogin(['code' => $this->wrongCode()])->assertStatus(422);
        }

        $this->actingAs($this->user)
            ->post(route('two-factor.challenge.verify'), ['code' => $this->codeAt(0)])
            ->assertSessionHasErrors('code');

        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));
    }

    public function test_web_challenge_failures_lock_the_api_login_too(): void
    {
        $this->actingAs($this->user);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('two-factor.challenge.verify'), ['code' => $this->wrongCode()])
                ->assertSessionHasErrors('code');
        }

        $this->app['auth']->forgetGuards();
        $this->apiLogin(['code' => $this->codeAt(0)])->assertStatus(429);
        $this->assertSame(0, $this->tokenCount($this->user));
    }

    public function test_one_accounts_lockout_does_not_affect_another_account(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->apiLogin(['code' => $this->wrongCode()])->assertStatus(422);
        }

        $otherSecret = decrypt($this->otherUser->two_factor_secret);
        $this->apiLogin(['code' => $this->google2fa->getCurrentOtp($otherSecret)], 'other@example.com')
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_a_wrong_password_does_not_spend_the_second_factor_budget(): void
    {
        // Without the password nobody can lock a user's second factor.
        for ($i = 0; $i < 6; $i++) {
            $this->apiLogin(['password' => 'wrong', 'code' => $this->wrongCode()])->assertStatus(422)->assertJsonValidationErrors(['email']);
        }

        $this->apiLogin(['code' => $this->codeAt(0)])->assertOk();
    }

    public function test_a_few_mistakes_followed_by_the_right_code_still_sign_in(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->apiLogin(['code' => $this->wrongCode()])->assertStatus(422);
        }

        $this->apiLogin(['code' => $this->codeAt(0)])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_the_lockout_expires(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->apiLogin(['code' => $this->wrongCode()])->assertStatus(422);
        }
        $this->apiLogin(['code' => $this->codeAt(0)])->assertStatus(429);

        $this->travel(16)->minutes();

        $this->apiLogin(['code' => $this->codeAt(0)])->assertOk();
    }

    // ── Replay ────────────────────────────────────────────────────────

    public function test_an_accepted_api_code_cannot_be_replayed(): void
    {
        $code = $this->codeAt(0);

        $this->apiLogin(['code' => $code])->assertOk();
        $this->apiLogin(['code' => $code])->assertStatus(422)->assertJsonValidationErrors(['code']);

        $this->assertSame(1, $this->tokenCount($this->user));
    }

    public function test_a_code_accepted_at_the_web_challenge_cannot_be_replayed_on_the_api(): void
    {
        $code = $this->codeAt(0);

        $this->actingAs($this->user)
            ->post(route('two-factor.challenge.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->app['auth']->forgetGuards();
        $this->apiLogin(['code' => $code])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->assertSame(0, $this->tokenCount($this->user));
    }

    public function test_a_code_accepted_at_the_web_challenge_cannot_be_replayed_at_the_web_challenge(): void
    {
        $code = $this->codeAt(0);

        $this->actingAs($this->user)
            ->post(route('two-factor.challenge.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->flushSession();

        $this->actingAs($this->user)
            ->post(route('two-factor.challenge.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');
    }

    public function test_a_newer_code_is_accepted_after_an_older_one(): void
    {
        $this->apiLogin(['code' => $this->codeAt(0)])->assertOk();
        $this->apiLogin(['code' => $this->codeAt(1)])->assertOk();

        // ...but the earlier code is now stale.
        $this->apiLogin(['code' => $this->codeAt(0)])->assertStatus(422);
    }

    public function test_the_code_that_enabled_two_factor_cannot_be_replayed_at_sign_in(): void
    {
        $fresh = User::create([
            'name' => 'Fresh',
            'email' => 'fresh@example.com',
            'password' => Hash::make('password123'),
            'organization_id' => $this->user->organization_id,
            'role' => 'member',
        ]);
        $secret = $this->google2fa->generateSecretKey();
        $code = $this->google2fa->getCurrentOtp($secret);

        $this->actingAs($fresh)
            ->withSession(['two_factor_secret' => $secret])
            ->post(route('two-factor.enable'), ['code' => $code])
            ->assertRedirect(route('settings.account.index'));
        $this->assertTrue($fresh->fresh()->two_factor_enabled);

        $this->app['auth']->forgetGuards();
        $this->apiLogin(['code' => $code], 'fresh@example.com')->assertStatus(422)->assertJsonValidationErrors(['code']);
    }
}
