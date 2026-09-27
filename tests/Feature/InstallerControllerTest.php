<?php

namespace Tests\Feature;

use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallerControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_admin_is_blocked_after_installation_completes(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $response = $this->postJson('/install/admin', [
            'organization_name' => 'Hijack Org',
            'admin_name' => 'Mallory',
            'admin_email' => 'mallory@example.com',
            'admin_password' => 'StrongPass123',
            'admin_password_confirmation' => 'StrongPass123',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Application is already installed.',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'mallory@example.com']);
        $this->assertDatabaseMissing('organizations', ['name' => 'Hijack Org']);
    }

    public function test_installer_php_requirement_matches_composer_json(): void
    {
        // The wizard used to advertise PHP 8.2 while the locked dependencies
        // need far newer; the stated minimum must be composer.json's.
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $minimum = ltrim((string) $composer['require']['php'], '^~>=');

        $response = $this->get('/install/requirements');

        $response->assertOk();
        $php = collect($response->viewData('page')['props']['requirements'])->firstWhere('name', 'PHP Version');
        $this->assertSame($minimum, $php['required']);
    }
}
