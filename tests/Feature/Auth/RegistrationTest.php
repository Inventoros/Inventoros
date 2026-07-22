<?php

namespace Tests\Feature\Auth;

use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');
    }

    public function test_registration_is_disabled_by_default(): void
    {
        // Self-registration mints an org-less account with no permissions and,
        // before the tenant scope was fixed, could read every tenant's data.
        // It must be off unless an operator explicitly opts in.
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_registration_screen_can_be_rendered_when_enabled(): void
    {
        config(['auth.registration_enabled' => true]);

        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register_when_enabled(): void
    {
        config(['auth.registration_enabled' => true]);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
