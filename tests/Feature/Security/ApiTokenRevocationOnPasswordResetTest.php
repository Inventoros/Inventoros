<?php

namespace Tests\Feature\Security;

use App\Enums\SecurityEvent;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Account recovery (an emailed password reset) and an administrator setting
 * another user's password revoke that user's API tokens; a user changing
 * their own password while signed in does not.
 */
class ApiTokenRevocationOnPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create(['name' => 'Org', 'email' => 'org@example.com']);
        $this->admin = $this->staff('admin@example.com', 'admin', $this->organization);
        $this->member = $this->staff('member@example.com', 'member', $this->organization);
    }

    private function staff(string $email, string $role, Organization $organization): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $organization->id,
            'role' => $role,
        ]);
    }

    private function tokenCount(User $user): int
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->count();
    }

    private function tokenWorks(string $plainTextToken): bool
    {
        $this->app['auth']->forgetGuards();

        $status = $this->withToken($plainTextToken)->getJson('/api/v1/user')->status();

        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $status === 200;
    }

    public function test_an_emailed_password_reset_revokes_the_users_api_tokens(): void
    {
        $plain = $this->member->createToken('integration')->plainTextToken;
        $this->member->createToken('second');
        $this->assertTrue($this->tokenWorks($plain));

        $token = Password::broker()->createToken($this->member);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $this->member->email,
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertSame(0, $this->tokenCount($this->member));
        $this->assertFalse($this->tokenWorks($plain));

        // Each revocation reaches the security log.
        $this->assertSame(2, ActivityLog::where('category', SecurityEvent::CATEGORY)
            ->where('action', SecurityEvent::API_TOKEN_REVOKED->value)
            ->count());
    }

    public function test_the_full_forgot_password_flow_revokes_tokens(): void
    {
        Notification::fake();
        $this->member->createToken('integration');

        $this->post('/forgot-password', ['email' => $this->member->email]);

        Notification::assertSentTo($this->member, ResetPassword::class, function (ResetPassword $notification) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $this->member->email,
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertSame(0, $this->tokenCount($this->member));
    }

    public function test_a_failed_password_reset_keeps_the_tokens(): void
    {
        $this->member->createToken('integration');

        $this->post('/reset-password', [
            'token' => 'not-a-valid-token',
            'email' => $this->member->email,
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, $this->tokenCount($this->member));
    }

    public function test_a_password_reset_only_revokes_the_reset_users_tokens(): void
    {
        $this->member->createToken('integration');
        $this->admin->createToken('admin-integration');

        $this->post('/reset-password', [
            'token' => Password::broker()->createToken($this->member),
            'email' => $this->member->email,
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->tokenCount($this->member));
        $this->assertSame(1, $this->tokenCount($this->admin));
    }

    public function test_an_administrator_setting_another_users_password_revokes_that_users_tokens(): void
    {
        $plain = $this->member->createToken('integration')->plainTextToken;
        $this->admin->createToken('admin-integration');

        $this->actingAs($this->admin)
            ->put(route('users.update', $this->member), [
                'name' => $this->member->name,
                'email' => $this->member->email,
                'role' => 'member',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])->assertSessionHasNoErrors()->assertRedirect(route('users.index'));

        $this->assertSame(0, $this->tokenCount($this->member));
        $this->assertSame(1, $this->tokenCount($this->admin));
        $this->assertFalse($this->tokenWorks($plain));
    }

    public function test_an_administrator_setting_another_users_password_over_the_api_revokes_that_users_tokens(): void
    {
        $this->member->createToken('integration');
        $adminToken = $this->admin->createToken('admin-integration')->plainTextToken;

        $this->withToken($adminToken)
            ->putJson('/api/v1/users/'.$this->member->id, [
                'name' => $this->member->name,
                'email' => $this->member->email,
                'role' => 'member',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])->assertOk();

        $this->assertSame(0, $this->tokenCount($this->member));
        $this->assertSame(1, $this->tokenCount($this->admin));
    }

    public function test_an_administrator_editing_another_user_without_a_new_password_keeps_their_tokens(): void
    {
        $this->member->createToken('integration');

        $this->actingAs($this->admin)
            ->put(route('users.update', $this->member), [
                'name' => 'Renamed Member',
                'email' => $this->member->email,
                'role' => 'member',
            ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->tokenCount($this->member));
    }

    public function test_a_self_service_password_change_keeps_the_users_tokens(): void
    {
        $plain = $this->member->createToken('integration')->plainTextToken;

        $this->actingAs($this->member)
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])->assertSessionHasNoErrors();

        $this->actingAs($this->member)
            ->patch(route('settings.account.update.password'), [
                'current_password' => 'a-new-password',
                'password' => 'another-password',
                'password_confirmation' => 'another-password',
            ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->tokenCount($this->member));
        $this->assertTrue($this->tokenWorks($plain));
    }
}
