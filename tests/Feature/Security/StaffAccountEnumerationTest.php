<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Unauthenticated staff endpoints must not reveal whether an email belongs
 * to an account: the password-reset request answers the same way for every
 * address, and the API login does the same password-hashing work for an
 * unknown email as for a wrong password.
 */
class StaffAccountEnumerationTest extends TestCase
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
            'name' => 'Known',
            'email' => 'known@example.com',
            'password' => Hash::make('password'),
            'organization_id' => $organization->id,
            'role' => 'member',
        ]);
    }

    private function generic(): string
    {
        return 'If that email belongs to an account, we have emailed a password reset link.';
    }

    public function test_reset_request_for_an_unknown_email_gets_the_generic_response(): void
    {
        Notification::fake();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', $this->generic());

        Notification::assertNothingSent();
    }

    public function test_reset_request_for_a_known_email_gets_the_same_response_and_sends_the_link(): void
    {
        Notification::fake();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'known@example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', $this->generic());

        Notification::assertSentTo($this->user, ResetPassword::class);
    }

    public function test_a_repeat_request_refused_by_the_broker_throttle_gets_the_same_response(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'known@example.com']);

        // The broker refuses a second link within its throttle window; that
        // refusal only ever happens for real accounts, so it must not show.
        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'known@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', $this->generic());

        Notification::assertSentToTimes($this->user, ResetPassword::class, 1);
    }

    public function test_reset_requests_for_unknown_emails_are_still_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => "nobody{$i}@example.com"])->assertStatus(302);
        }

        $this->post('/forgot-password', ['email' => 'nobody9@example.com'])->assertStatus(429);
    }

    public function test_reset_request_still_validates_the_email_format(): void
    {
        $this->post('/forgot-password', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
    }

    public function test_api_login_hashes_the_password_for_an_unknown_email(): void
    {
        Hash::spy();

        $this->postJson('/api/v1/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // One password hash, the same work as checking a wrong password for
        // a known account, so response time does not reveal which emails exist.
        Hash::shouldHaveReceived('make')->once();
    }

    public function test_api_login_unknown_email_and_wrong_password_answer_identically(): void
    {
        $unknown = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->postJson('/api/v1/login', ['email' => 'nobody@example.com', 'password' => 'whatever']);
        $wrong = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])
            ->postJson('/api/v1/login', ['email' => 'known@example.com', 'password' => 'whatever']);

        $unknown->assertStatus(422);
        $wrong->assertStatus(422);
        $this->assertSame($wrong->json(), $unknown->json());
    }

    public function test_api_login_with_the_right_password_still_works(): void
    {
        $this->postJson('/api/v1/login', ['email' => 'known@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }
}
