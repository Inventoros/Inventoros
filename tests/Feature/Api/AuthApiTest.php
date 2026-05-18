<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'user@test.com',
            'password' => bcrypt('password123'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => ['view_products', 'manage_products'],
            ]
        );

        $this->user->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    // ==================== LOGIN TESTS ====================

    public function test_can_login_with_valid_credentials(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'user@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'email'],
                'token',
            ])
            ->assertJsonPath('message', 'Login successful');
    }

    public function test_cannot_login_with_invalid_email(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'wrong@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_cannot_login_with_invalid_password(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'user@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_validates_email_format(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'not-an-email',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ==================== 2FA LOGIN TESTS ====================

    protected function enableTwoFactorFor(User $user): array
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();
        $recoveryCodes = ['recovery-aaaa-1111', 'recovery-bbbb-2222'];

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
        ])->save();

        return [$secret, $recoveryCodes];
    }

    public function test_login_fails_when_2fa_enabled_and_code_missing(): void
    {
        $this->enableTwoFactorFor($this->user);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'user@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $this->user->id)->count());
    }

    public function test_login_succeeds_with_valid_totp_when_2fa_enabled(): void
    {
        [$secret] = $this->enableTwoFactorFor($this->user);
        $otp = (new Google2FA())->getCurrentOtp($secret);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'user@test.com',
            'password' => 'password123',
            'code' => $otp,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_login_fails_with_invalid_totp(): void
    {
        $this->enableTwoFactorFor($this->user);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'user@test.com',
            'password' => 'password123',
            'code' => '000000',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $this->user->id)->count());
    }

    public function test_login_succeeds_with_valid_recovery_code_and_consumes_it(): void
    {
        [, $recoveryCodes] = $this->enableTwoFactorFor($this->user);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'user@test.com',
            'password' => 'password123',
            'recovery_code' => $recoveryCodes[0],
        ]);

        $response->assertStatus(200);

        // After consume: the matched code is gone AND, per P0-12, the
        // remaining legacy plaintext codes have been migrated to their
        // sha256-hashed form so the next DB read can't leak plaintext.
        $stored = json_decode(decrypt($this->user->fresh()->two_factor_recovery_codes), true);
        $this->assertNotContains($recoveryCodes[0], $stored);
        $this->assertNotContains($recoveryCodes[1], $stored, 'legacy plaintext must not remain after a recovery-code consume');
        $this->assertContains(hash('sha256', $recoveryCodes[1]), $stored);
    }

    public function test_recovery_codes_stored_as_hashes_still_verify(): void
    {
        // Simulate a user who enrolled in 2FA after the P0-12 fix landed:
        // their stored codes are sha256 hashes, not plaintext.
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();
        $plain = ['post-fix-aaa', 'post-fix-bbb'];

        $this->user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(
                \App\Http\Controllers\Auth\TwoFactorController::hashRecoveryCodes($plain)
            )),
        ])->save();

        // Verify the at-rest representation does not contain plaintext.
        $raw = json_decode(decrypt($this->user->fresh()->two_factor_recovery_codes), true);
        $this->assertNotContains($plain[0], $raw);
        $this->assertContains(hash('sha256', $plain[0]), $raw);

        // Login with the plaintext recovery code should still succeed.
        $this->postJson('/api/v1/login', [
            'email' => 'user@test.com',
            'password' => 'password123',
            'recovery_code' => $plain[0],
        ])->assertStatus(200);

        $remaining = json_decode(decrypt($this->user->fresh()->two_factor_recovery_codes), true);
        $this->assertNotContains(hash('sha256', $plain[0]), $remaining);
        $this->assertContains(hash('sha256', $plain[1]), $remaining);
    }

    public function test_recovery_code_cannot_be_used_twice(): void
    {
        [, $recoveryCodes] = $this->enableTwoFactorFor($this->user);

        $this->postJson('/api/v1/login', [
            'email' => 'user@test.com',
            'password' => 'password123',
            'recovery_code' => $recoveryCodes[0],
        ])->assertStatus(200);

        $second = $this->postJson('/api/v1/login', [
            'email' => 'user@test.com',
            'password' => 'password123',
            'recovery_code' => $recoveryCodes[0],
        ]);

        $second->assertStatus(422)->assertJsonValidationErrors(['recovery_code']);
    }

    // ==================== LOGOUT TESTS ====================

    public function test_can_logout(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/logout');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Logged out successfully');
    }

    public function test_unauthenticated_cannot_logout(): void
    {
        $response = $this->postJson('/api/v1/logout');

        $response->assertStatus(401);
    }

    // ==================== GET USER TESTS ====================

    public function test_can_get_authenticated_user(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/user');

        $response->assertStatus(200)
            ->assertJsonPath('id', $this->user->id)
            ->assertJsonPath('email', 'user@test.com')
            ->assertJsonPath('name', 'Test User');
    }

    public function test_unauthenticated_cannot_get_user(): void
    {
        $response = $this->getJson('/api/v1/user');

        $response->assertStatus(401);
    }

    public function test_user_response_includes_organization(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/user');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'name',
                'email',
                'organization',
            ]);
    }

    // ==================== TOKEN MANAGEMENT TESTS ====================

    public function test_can_create_api_token(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/tokens', [
            'name' => 'My API Token',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'token',
                'name',
            ]);
    }

    public function test_create_token_validates_name(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/tokens', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_can_create_token_with_abilities(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/tokens', [
            'name' => 'Limited Token',
            'abilities' => ['view_products', 'manage_stock'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('abilities', ['view_products', 'manage_stock']);
    }

    public function test_create_token_rejects_unknown_abilities(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/tokens', [
            'name' => 'Bogus Token',
            'abilities' => ['view_products', 'not_a_real_permission'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['abilities.1']);
    }

    public function test_admin_can_create_wildcard_token_by_default(): void
    {
        Sanctum::actingAs($this->user); // setUp() created this user with role='admin'.

        $response = $this->postJson('/api/v1/tokens', [
            'name' => 'Admin Default Token',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('abilities', ['*']);
    }

    public function test_non_admin_default_token_has_no_abilities(): void
    {
        $member = User::create([
            'name' => 'Member', 'email' => 'mb@test.com', 'password' => bcrypt('x'),
            'organization_id' => $this->organization->id, 'role' => 'member',
        ]);

        Sanctum::actingAs($member);

        $response = $this->postJson('/api/v1/tokens', [
            'name' => 'Member Default Token',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('abilities', []);
    }

    public function test_non_admin_cannot_request_wildcard_token(): void
    {
        $member = User::create([
            'name' => 'Member2', 'email' => 'mb2@test.com', 'password' => bcrypt('x'),
            'organization_id' => $this->organization->id, 'role' => 'member',
        ]);

        Sanctum::actingAs($member);

        $response = $this->postJson('/api/v1/tokens', [
            'name' => 'Member Wildcard',
            'abilities' => ['*'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['abilities']);
    }

    public function test_can_revoke_token(): void
    {
        Sanctum::actingAs($this->user);

        // Create a token first
        $token = $this->user->createToken('Test Token');
        $tokenId = $token->accessToken->id;

        $response = $this->deleteJson("/api/v1/tokens/{$tokenId}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Token revoked successfully');

        $this->assertNull(PersonalAccessToken::find($tokenId));
    }

    public function test_cannot_revoke_other_users_token(): void
    {
        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $otherToken = $otherUser->createToken('Other Token');
        $tokenId = $otherToken->accessToken->id;

        Sanctum::actingAs($this->user);

        $response = $this->deleteJson("/api/v1/tokens/{$tokenId}");

        $response->assertStatus(404);

        $this->assertNotNull(PersonalAccessToken::find($tokenId));
    }

    public function test_unauthenticated_cannot_create_token(): void
    {
        $response = $this->postJson('/api/v1/tokens', [
            'name' => 'My Token',
        ]);

        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_revoke_token(): void
    {
        $response = $this->deleteJson('/api/v1/tokens/1');

        $response->assertStatus(401);
    }
}
