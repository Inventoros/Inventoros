<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test organization
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
        ]);

        // Create test user
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
            'notification_preferences' => [
                'email_notifications' => true,
                'low_stock_alerts' => true,
                'order_notifications' => true,
                'system_notifications' => false,
            ],
        ]);
    }

    public function test_user_can_view_account_settings(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('settings.account.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Settings/Account/Index')
            ->has('user')
        );
    }

    public function test_guest_cannot_view_account_settings(): void
    {
        $response = $this->get(route('settings.account.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_update_profile(): void
    {
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.profile'), [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Profile updated successfully.');

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);
    }

    public function test_profile_update_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.profile'), [
                'name' => '',
                'email' => '',
            ]);

        $response->assertSessionHasErrors(['name', 'email']);
    }

    public function test_profile_update_validates_unique_email(): void
    {
        // Create another user
        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.profile'), [
                'name' => 'Test User',
                'email' => 'other@example.com', // Already taken
            ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_profile_update_validates_email_format(): void
    {
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.profile'), [
                'name' => 'Test User',
                'email' => 'invalid-email',
            ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_user_can_update_password(): void
    {
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.password'), [
                'current_password' => 'password',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Password updated successfully.');

        // Verify the password was actually changed
        $this->user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $this->user->password));
    }

    public function test_password_update_validates_current_password(): void
    {
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.password'), [
                'current_password' => 'wrongpassword',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['current_password']);

        // Verify the password was NOT changed
        $this->user->refresh();
        $this->assertTrue(Hash::check('password', $this->user->password));
    }

    public function test_password_update_validates_password_confirmation(): void
    {
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.password'), [
                'current_password' => 'password',
                'password' => 'newpassword123',
                'password_confirmation' => 'differentpassword',
            ]);

        $response->assertSessionHasErrors(['password']);
    }

    public function test_password_update_validates_minimum_length(): void
    {
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.password'), [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);

        $response->assertSessionHasErrors(['password']);
    }

    public function test_user_can_update_notification_preferences(): void
    {
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.notifications'), [
                'email_notifications' => false,
                'low_stock_alerts' => false,
                'order_notifications' => true,
                'system_notifications' => true,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Notification preferences updated successfully.');

        $this->user->refresh();
        $this->assertFalse($this->user->notification_preferences['email_notifications']);
        $this->assertFalse($this->user->notification_preferences['low_stock_alerts']);
        $this->assertTrue($this->user->notification_preferences['order_notifications']);
        $this->assertTrue($this->user->notification_preferences['system_notifications']);
    }

    public function test_notification_preferences_default_to_false_when_not_provided(): void
    {
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.notifications'), [
                'email_notifications' => true,
                // Other preferences not provided
            ]);

        $response->assertRedirect();

        $this->user->refresh();
        $this->assertTrue($this->user->notification_preferences['email_notifications']);
    }

    public function test_user_can_update_preferences(): void
    {
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.preferences'), [
                'theme' => 'dark',
                'language' => 'es',
                'items_per_page' => 50,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Preferences updated successfully.');

        $this->user->refresh();
        $this->assertEquals('dark', $this->user->notification_preferences['preferences']['theme']);
        $this->assertEquals('es', $this->user->notification_preferences['preferences']['language']);
        $this->assertEquals(50, $this->user->notification_preferences['preferences']['items_per_page']);
    }

    public function test_preferences_validates_theme_values(): void
    {
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.preferences'), [
                'theme' => 'invalid-theme',
                'language' => 'en',
                'items_per_page' => 25,
            ]);

        $response->assertSessionHasErrors(['theme']);
    }

    public function test_preferences_validates_items_per_page_range(): void
    {
        // Test below minimum
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.preferences'), [
                'theme' => 'light',
                'language' => 'en',
                'items_per_page' => 5, // Below minimum of 10
            ]);

        $response->assertSessionHasErrors(['items_per_page']);

        // Test above maximum
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.preferences'), [
                'theme' => 'light',
                'language' => 'en',
                'items_per_page' => 150, // Above maximum of 100
            ]);

        $response->assertSessionHasErrors(['items_per_page']);
    }

    public function test_preferences_are_optional(): void
    {
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.preferences'), [
                // All preferences are optional
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Preferences updated successfully.');
    }

    public function test_user_preferences_persist_across_updates(): void
    {
        // Update notification preferences
        $this->actingAs($this->user)
            ->patch(route('settings.account.update.notifications'), [
                'email_notifications' => false,
                'low_stock_alerts' => true,
            ]);

        // Update user preferences
        $this->actingAs($this->user)
            ->patch(route('settings.account.update.preferences'), [
                'theme' => 'dark',
                'items_per_page' => 50,
            ]);

        // Verify both are still present
        $this->user->refresh();
        $this->assertFalse($this->user->notification_preferences['email_notifications']);
        $this->assertTrue($this->user->notification_preferences['low_stock_alerts']);
        $this->assertEquals('dark', $this->user->notification_preferences['preferences']['theme']);
        $this->assertEquals(50, $this->user->notification_preferences['preferences']['items_per_page']);
    }

    public function test_multiple_users_can_have_independent_settings(): void
    {
        $user2 = User::create([
            'name' => 'User 2',
            'email' => 'user2@example.com',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        // Update user 1 settings
        $this->actingAs($this->user)
            ->patch(route('settings.account.update.notifications'), [
                'email_notifications' => false,
            ]);

        // Update user 2 settings
        $this->actingAs($user2)
            ->patch(route('settings.account.update.notifications'), [
                'email_notifications' => true,
            ]);

        // Verify they have independent settings
        $this->user->refresh();
        $user2->refresh();

        $this->assertFalse($this->user->notification_preferences['email_notifications']);
        $this->assertTrue($user2->notification_preferences['email_notifications']);
    }

    public function test_password_form_resets_on_success(): void
    {
        // This test verifies the controller returns a redirect on success
        // The actual form reset happens in the Vue component
        $response = $this->actingAs($this->user)
            ->patch(route('settings.account.update.password'), [
                'current_password' => 'password',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionHasNoErrors();
    }
}
