<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Install\InstallerController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * .env.example ships APP_ENV=local and APP_DEBUG=true, and nothing changed
 * them: a web-installed site ran in debug mode, showing stack traces (paths,
 * queries, settings) to anyone who hit an error. Finishing the installation
 * now switches it to production settings.
 */
final class InstallerProductionSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envFile = storage_path('app/testing-production-settings/installer.env');
        File::ensureDirectoryExists(dirname($this->envFile));
        file_put_contents($this->envFile, implode("\n", [
            'APP_NAME=Inventoros',
            'APP_ENV=local',
            'APP_DEBUG=true',
            'APP_URL=http://inventory.example.com',
            'SESSION_SECURE_COOKIE="false"',
            'SESSION_DRIVER=database',
        ])."\n");

        $envFile = $this->envFile;
        $this->app->bind(InstallerController::class, fn () => new class($envFile) extends InstallerController
        {
            public function __construct(private string $testEnvFile) {}

            protected function envFilePath(): string
            {
                return $this->testEnvFile;
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->envFile));

        parent::tearDown();
    }

    private function createAdmin(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/install/admin', [
            'organization_name' => 'Acme',
            'admin_name' => 'Ada',
            'admin_email' => 'ada@example.com',
            'admin_password' => 'StrongPass123',
            'admin_password_confirmation' => 'StrongPass123',
        ]);
    }

    public function test_completing_the_installation_switches_to_production_settings(): void
    {
        $this->createAdmin()->assertOk()->assertJson(['success' => true]);

        $env = (string) file_get_contents($this->envFile);
        $this->assertMatchesRegularExpression('/^APP_ENV="production"$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_DEBUG="false"$/m', $env);
        $this->assertDoesNotMatchRegularExpression('/^APP_ENV=local$/m', $env);
        $this->assertDoesNotMatchRegularExpression('/^APP_DEBUG=true$/m', $env);
    }

    public function test_the_values_parse_back_as_production_and_debug_off(): void
    {
        $this->createAdmin()->assertOk();

        $values = \Dotenv\Dotenv::parse((string) file_get_contents($this->envFile));
        $this->assertSame('production', $values['APP_ENV']);
        $this->assertSame('false', $values['APP_DEBUG']);
    }

    public function test_the_url_and_the_secure_cookie_choice_are_left_alone(): void
    {
        $this->createAdmin()->assertOk();

        $env = (string) file_get_contents($this->envFile);
        $this->assertMatchesRegularExpression('#^APP_URL=http://inventory\.example\.com$#m', $env);
        $this->assertMatchesRegularExpression('/^SESSION_SECURE_COOKIE="false"$/m', $env);
    }

    public function test_the_installed_version_is_the_release_version(): void
    {
        // It was config('app.version'), which is not defined: always 0.1.0.
        $this->createAdmin()->assertOk();

        $this->assertSame(\App\Support\AppVersion::current(), \App\Models\System\SystemSetting::get('app_version'));
    }

    public function test_a_failed_admin_step_leaves_the_environment_unchanged(): void
    {
        $this->postJson('/install/admin', ['organization_name' => 'Acme'])->assertStatus(422);

        $env = (string) file_get_contents($this->envFile);
        $this->assertMatchesRegularExpression('/^APP_ENV=local$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_DEBUG=true$/m', $env);
    }
}
