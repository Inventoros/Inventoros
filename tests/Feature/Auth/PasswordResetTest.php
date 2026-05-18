<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\System\SystemSetting::set('installed', true, 'boolean');
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        // The route is throttled at 5 attempts per minute; the 6th must 429.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => $user->email])
                ->assertStatus(302); // valid email returns a redirect
        }

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertStatus(429);
    }

    public function test_reset_password_is_rate_limited(): void
    {
        // Send 5 invalid reset attempts; the 6th must 429.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/reset-password', [
                'token' => 'invalid-token',
                'email' => 'someone@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);
        }

        $this->post('/reset-password', [
            'token' => 'invalid-token',
            'email' => 'someone@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(429);
    }
}
