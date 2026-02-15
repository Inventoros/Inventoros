<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_profile_update_requires_name(): void
    {
        $response = $this->actingAs($this->user)
            ->patch('/profile', [
                'name' => '',
                'email' => $this->user->email,
            ]);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_profile_update_requires_email(): void
    {
        $response = $this->actingAs($this->user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => '',
            ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_profile_update_requires_valid_email_format(): void
    {
        $response = $this->actingAs($this->user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'not-an-email',
            ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_profile_update_requires_unique_email(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($this->user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $otherUser->email,
            ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_profile_update_name_max_length(): void
    {
        $response = $this->actingAs($this->user)
            ->patch('/profile', [
                'name' => str_repeat('a', 256),
                'email' => $this->user->email,
            ]);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_profile_update_email_max_length(): void
    {
        $response = $this->actingAs($this->user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => str_repeat('a', 250) . '@test.com',
            ]);

        $response->assertSessionHasErrors(['email']);
    }
}
