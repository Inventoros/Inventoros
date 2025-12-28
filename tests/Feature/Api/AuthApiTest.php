<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
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
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token',
                ],
            ])
            ->assertJsonPath('message', 'Login successful');
    }

    public function test_cannot_login_with_invalid_email(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'wrong@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_cannot_login_with_invalid_password(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'user@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
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
            ->assertJsonPath('data.id', $this->user->id)
            ->assertJsonPath('data.email', 'user@test.com')
            ->assertJsonPath('data.name', 'Test User');
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
                'data' => [
                    'id',
                    'name',
                    'email',
                    'organization',
                ],
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
                'data' => [
                    'token',
                    'name',
                ],
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
            'abilities' => ['read', 'write'],
        ]);

        $response->assertStatus(201);
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
