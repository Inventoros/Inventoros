<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_requires_email(): void
    {
        $response = $this->post('/login', [
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_login_requires_password(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
        ]);

        $response->assertSessionHasErrors(['password']);
    }

    public function test_login_requires_valid_email_format(): void
    {
        $response = $this->post('/login', [
            'email' => 'not-an-email',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_login_requires_email_to_be_string(): void
    {
        $response = $this->post('/login', [
            'email' => ['array', 'value'],
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(['email']);
    }
}
