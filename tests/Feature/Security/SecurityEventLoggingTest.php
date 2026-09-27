<?php

namespace Tests\Feature\Security;

use App\Enums\SecurityEvent;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SecurityEventLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Security Org',
            'email' => 'sec@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->user = User::factory()->forOrganization($this->organization->id)->create([
            'email' => 'person@example.com',
        ]);
    }

    private function securityLogs(SecurityEvent $event)
    {
        return ActivityLog::where('category', SecurityEvent::CATEGORY)
            ->where('action', $event->value)
            ->get();
    }

    public function test_successful_login_is_logged_under_the_users_organization(): void
    {
        $this->withHeader('User-Agent', 'TestAgent/1.0')->post('/login', [
            'email' => 'person@example.com',
            'password' => 'password',
        ]);

        $logs = $this->securityLogs(SecurityEvent::LOGIN);
        $this->assertCount(1, $logs);
        $this->assertSame($this->organization->id, $logs[0]->organization_id);
        $this->assertSame($this->user->id, $logs[0]->user_id);
        $this->assertSame(User::class, $logs[0]->subject_type);
        $this->assertSame($this->user->id, $logs[0]->subject_id);
        $this->assertSame('TestAgent/1.0', $logs[0]->user_agent);
        $this->assertNotNull($logs[0]->ip_address);
    }

    public function test_logout_is_logged(): void
    {
        $this->actingAs($this->user)->post('/logout');

        $logs = $this->securityLogs(SecurityEvent::LOGOUT);
        $this->assertCount(1, $logs);
        $this->assertSame($this->user->id, $logs[0]->subject_id);
    }

    public function test_failed_login_for_a_known_user_is_logged_without_the_password(): void
    {
        $this->withHeader('User-Agent', 'Mallory/2.0')->post('/login', [
            'email' => 'person@example.com',
            'password' => 'hunter2-secret',
        ]);

        $logs = $this->securityLogs(SecurityEvent::LOGIN_FAILED);
        $this->assertCount(1, $logs);
        $log = $logs[0];
        $this->assertSame($this->organization->id, $log->organization_id);
        $this->assertNull($log->user_id, 'The actor of a failed login is unknown');
        $this->assertSame($this->user->id, $log->subject_id);
        $this->assertSame('person@example.com', $log->properties['email']);
        $this->assertSame('Mallory/2.0', $log->user_agent);

        $raw = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('hunter2-secret', $raw);
        $this->assertArrayNotHasKey('password', $log->properties);
    }

    public function test_failed_login_for_an_unknown_email_goes_to_the_application_log_only(): void
    {
        Log::spy();

        $this->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'guess-password',
        ]);

        $this->assertSame(0, ActivityLog::where('category', SecurityEvent::CATEGORY)->count());

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            return str_contains($message, 'unknown email')
                && ($context['email'] ?? null) === 'nobody@example.com'
                && ! str_contains(json_encode($context), 'guess-password');
        })->once();
    }

    public function test_lockout_is_logged_once_per_throttle_window(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->post('/login', [
                'email' => 'person@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $this->assertCount(1, $this->securityLogs(SecurityEvent::LOCKOUT));
    }

    public function test_password_reset_request_and_completion_are_logged(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'person@example.com']);

        $this->assertCount(1, $this->securityLogs(SecurityEvent::PASSWORD_RESET_REQUESTED));

        $token = Password::broker()->createToken($this->user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'person@example.com',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $logs = $this->securityLogs(SecurityEvent::PASSWORD_RESET);
        $this->assertCount(1, $logs);
        $this->assertStringNotContainsString('a-brand-new-password', json_encode($logs[0]->getAttributes()));
    }

    public function test_two_factor_enable_disable_and_failed_challenge_are_logged(): void
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        $this->actingAs($this->user)
            ->withSession(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => ['abc']])
            ->post(route('two-factor.enable'), ['code' => $google2fa->getCurrentOtp($secret)]);

        $this->assertCount(1, $this->securityLogs(SecurityEvent::TWO_FACTOR_ENABLED));

        $this->user->refresh();

        $this->actingAs($this->user)
            ->post(route('two-factor.challenge.verify'), ['code' => '000000']);

        $this->assertCount(1, $this->securityLogs(SecurityEvent::TWO_FACTOR_FAILED));

        $this->actingAs($this->user)
            ->withSession(['two_factor_verified' => true])
            ->post(route('two-factor.disable'), ['password' => 'password']);

        $this->assertCount(1, $this->securityLogs(SecurityEvent::TWO_FACTOR_DISABLED));
    }

    public function test_api_token_creation_and_revocation_are_logged(): void
    {
        $token = $this->user->createToken('ci-token');

        $created = $this->securityLogs(SecurityEvent::API_TOKEN_CREATED);
        $this->assertCount(1, $created);
        $this->assertSame('ci-token', $created[0]->properties['token_name']);
        $this->assertSame($this->organization->id, $created[0]->organization_id);

        $token->accessToken->delete();

        $revoked = $this->securityLogs(SecurityEvent::API_TOKEN_REVOKED);
        $this->assertCount(1, $revoked);
        $this->assertSame('ci-token', $revoked[0]->properties['token_name']);
    }

    public function test_api_login_success_and_failure_are_logged(): void
    {
        $this->postJson('/api/v1/login', [
            'email' => 'person@example.com',
            'password' => 'nope-nope',
        ])->assertStatus(422);

        $this->assertCount(1, $this->securityLogs(SecurityEvent::LOGIN_FAILED));

        $this->postJson('/api/v1/login', [
            'email' => 'person@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->assertCount(1, $this->securityLogs(SecurityEvent::LOGIN));
    }

    public function test_permission_denied_is_logged_once_and_rate_limited(): void
    {
        $role = Role::firstOrCreate(['slug' => 'system-member'], [
            'name' => 'Member', 'is_system' => true, 'permissions' => [],
        ]);
        $this->user->roles()->sync([$role->id]);

        $this->actingAs($this->user)->get(route('activity-log.index'))->assertForbidden();
        $this->actingAs($this->user)->get(route('activity-log.index'))->assertForbidden();

        $logs = $this->securityLogs(SecurityEvent::PERMISSION_DENIED);
        $this->assertCount(1, $logs);
        $this->assertSame($this->user->id, $logs[0]->user_id);
        $this->assertSame('GET', $logs[0]->properties['method']);
        $this->assertStringContainsString('activity-log', $logs[0]->properties['path']);
    }

    public function test_permission_denied_from_the_api_permission_middleware_is_logged(): void
    {
        $role = Role::firstOrCreate(['slug' => 'system-member'], [
            'name' => 'Member', 'is_system' => true, 'permissions' => [],
        ]);
        $this->user->roles()->sync([$role->id]);

        $token = $this->user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/products')->assertForbidden();

        $this->assertCount(1, $this->securityLogs(SecurityEvent::PERMISSION_DENIED));
    }

    public function test_role_change_on_a_user_is_logged(): void
    {
        $admin = User::factory()->admin()->forOrganization($this->organization->id)->create();

        $this->actingAs($admin);
        $this->user->update(['role' => 'manager']);

        $logs = $this->securityLogs(SecurityEvent::USER_ROLE_CHANGED);
        $this->assertCount(1, $logs);
        $this->assertSame('member', $logs[0]->properties['old_role']);
        $this->assertSame('manager', $logs[0]->properties['new_role']);
        $this->assertSame($admin->id, $logs[0]->user_id);
    }

    public function test_role_permission_changes_are_logged(): void
    {
        $admin = User::factory()->admin()->forOrganization($this->organization->id)->create();
        $this->actingAs($admin);

        $role = Role::create([
            'name' => 'Pickers',
            'organization_id' => $this->organization->id,
            'permissions' => ['view_products'],
            'is_system' => false,
        ]);
        $this->assertCount(1, $this->securityLogs(SecurityEvent::ROLE_CREATED));

        $role->update(['permissions' => ['view_products', 'manage_stock']]);
        $updated = $this->securityLogs(SecurityEvent::ROLE_UPDATED);
        $this->assertCount(1, $updated);
        $this->assertSame(['manage_stock'], $updated[0]->properties['permissions_added']);
        $this->assertSame([], $updated[0]->properties['permissions_removed']);

        $role->delete();
        $this->assertCount(1, $this->securityLogs(SecurityEvent::ROLE_DELETED));
    }

    public function test_activity_log_page_filters_by_security_category(): void
    {
        $adminRole = Role::firstOrCreate(['slug' => 'system-administrator'], [
            'name' => 'Administrator', 'is_system' => true, 'permissions' => ['view_activity_log'],
        ]);
        $admin = User::factory()->admin()->forOrganization($this->organization->id)->create();
        $admin->roles()->sync([$adminRole->id]);

        $this->post('/login', ['email' => 'person@example.com', 'password' => 'password']);
        $this->post('/logout');

        ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $admin->id,
            'subject_type' => User::class,
            'subject_id' => $admin->id,
            'action' => 'updated',
            'description' => 'Updated User',
        ]);

        $this->actingAs($admin)
            ->get(route('activity-log.index', ['category' => 'security']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.category', 'security')
                ->has('activities.data', 2)
                ->has('categories')
                ->where('activities.data.0.category', 'security')
            );
    }
}
