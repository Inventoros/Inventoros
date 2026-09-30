<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Install\InstallerController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * .env.example used to ship SESSION_SECURE_COOKIE=true. A browser never sends
 * a Secure cookie back over plain http, so an install reached over http lost
 * its session on every request and every installer POST answered 419. The
 * example now leaves it off, and the installer turns it on when the
 * installation is served over https.
 */
final class InstallerSecureCookieTest extends TestCase
{
    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envFile = storage_path('app/testing-secure-cookie/installer.env');
        File::ensureDirectoryExists(dirname($this->envFile));
        file_put_contents($this->envFile, "APP_NAME=Inventoros\nSESSION_SECURE_COOKIE=false\nDB_CONNECTION=sqlite\n");

        $envFile = $this->envFile;
        $this->app->bind(InstallerController::class, fn () => new class($envFile) extends InstallerController
        {
            public function __construct(private string $testEnvFile) {}

            protected function envFilePath(): string
            {
                return $this->testEnvFile;
            }

            // Only the .env this step writes is under test here.
            protected function prepareDatabase(string $driver, array $input, bool $reset): void {}
        });
        Artisan::shouldReceive('call')->andReturn(0);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->envFile));

        parent::tearDown();
    }

    private function install(string $url): void
    {
        $this->postJson($url, [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'inventoros',
            'username' => 'inventoros',
            'password' => 'secret',
        ])->assertOk();
    }

    public function test_env_example_does_not_force_secure_cookies_before_install(): void
    {
        $example = (string) File::get(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^SESSION_SECURE_COOKIE=false$/m', $example);
    }

    public function test_an_https_install_turns_secure_cookies_on(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->install('https://inventory.example.com/install/database/install');

        $this->assertStringContainsString('SESSION_SECURE_COOKIE="true"', (string) file_get_contents($this->envFile));
    }

    public function test_an_https_app_url_turns_secure_cookies_on(): void
    {
        // TLS ended at a proxy: the request looks like http, APP_URL says https.
        config(['app.url' => 'https://inventory.example.com']);

        $this->install('http://inventory.example.com/install/database/install');

        $this->assertStringContainsString('SESSION_SECURE_COOKIE="true"', (string) file_get_contents($this->envFile));
    }

    public function test_a_plain_http_install_leaves_secure_cookies_off(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->install('http://inventory.example.com/install/database/install');

        $env = (string) file_get_contents($this->envFile);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE="false"', $env);
        $this->assertStringNotContainsString('SESSION_SECURE_COOKIE="true"', $env);
    }
}
