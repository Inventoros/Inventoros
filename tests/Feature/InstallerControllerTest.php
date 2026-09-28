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
        $this->assertStringStartsWith($minimum, $php['required']);
    }

    public function test_installer_accepts_php_8_4_1_and_newer_including_8_5(): void
    {
        $supported = fn (string $version) => \App\Http\Controllers\Install\InstallerController::phpVersionSupported($version);

        $this->assertFalse($supported('8.3.20'));
        $this->assertFalse($supported('8.4.0'));
        $this->assertTrue($supported('8.4.1'));
        $this->assertTrue($supported('8.4.13'));
        // phpoffice/phpspreadsheet 5.x (Laravel Excel 4) installs on PHP 8.5.
        $this->assertTrue($supported('8.5.0'));
        $this->assertTrue($supported('8.5.4'));
    }

    public function test_installer_php_ceiling_follows_the_locked_phpspreadsheet(): void
    {
        // If phpspreadsheet is ever locked to a release with a PHP upper bound
        // again, this fails until the installer's ceiling matches it.
        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
        $package = collect($lock['packages'])->firstWhere('name', 'phpoffice/phpspreadsheet');
        $this->assertNotNull($package);

        preg_match('/<\s*([0-9.]+)/', (string) ($package['require']['php'] ?? ''), $m);
        $lockCeiling = $m[1] ?? null;

        $this->assertSame($lockCeiling, \App\Http\Controllers\Install\InstallerController::PHP_MAX_EXCLUSIVE);
    }

    public function test_requirements_page_states_the_upper_bound(): void
    {
        $response = $this->get('/install/requirements');

        $php = collect($response->viewData('page')['props']['requirements'])->firstWhere('name', 'PHP Version');
        $this->assertSame('8.4.1 or newer', $php['required']);
        $this->assertSame(\App\Http\Controllers\Install\InstallerController::phpVersionSupported(PHP_VERSION), $php['met']);
    }

    public function test_database_step_accepts_the_numeric_port_the_wizard_sends(): void
    {
        // The wizard posts `port` as a JSON number. Writing it to .env used to
        // hit quoteEnvValue(string) with an int: a TypeError, so every install
        // through the browser died with a bare 500 on the database step.
        $envFile = storage_path('app/testing/installer.env');
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($envFile));
        file_put_contents($envFile, "APP_NAME=Inventoros\nDB_CONNECTION=sqlite\n");

        $this->app->bind(\App\Http\Controllers\Install\InstallerController::class, fn () => new class($envFile) extends \App\Http\Controllers\Install\InstallerController
        {
            public function __construct(private string $testEnvFile) {}

            protected function envFilePath(): string
            {
                return $this->testEnvFile;
            }
        });
        \Illuminate\Support\Facades\Artisan::shouldReceive('call')->andReturn(0);

        $response = $this->postJson('/install/database/install', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'inventoros',
            'username' => 'inventoros',
            'password' => 'secret',
        ]);

        $this->assertNotSame('Server Error', $response->json('message'));
        $env = (string) file_get_contents($envFile);
        $this->assertStringContainsString('DB_CONNECTION="mysql"', $env);
        $this->assertStringContainsString('DB_PORT="3306"', $env);

        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/testing'));
    }
}
