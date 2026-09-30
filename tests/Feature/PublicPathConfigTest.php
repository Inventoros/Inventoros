<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Install\InstallerController;
use App\Support\PublicPath;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * On a split (cPanel) install the CLI must use the same web root as the web
 * front controller; APP_PUBLIC_PATH carries it.
 */
final class PublicPathConfigTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('app/testing/public_path_'.uniqid());
        File::ensureDirectoryExists($this->root.'/home/inventoros');
        File::ensureDirectoryExists($this->root.'/home/public_html');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/testing'));
        putenv('APP_PUBLIC_PATH');
        unset($_ENV['APP_PUBLIC_PATH'], $_SERVER['APP_PUBLIC_PATH']);

        parent::tearDown();
    }

    /**
     * Boot a second application the way `php artisan` does, then hand the
     * facades and container back to the test application.
     */
    private function bootFreshConsoleApp(?string $explicitPublicPath = null): \Illuminate\Foundation\Application
    {
        $app = require base_path('bootstrap/app.php');
        if ($explicitPublicPath !== null) {
            $app->usePublicPath($explicitPublicPath);
        }

        try {
            $app->make(Kernel::class)->bootstrap();
        } finally {
            \Illuminate\Container\Container::setInstance($this->app);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($this->app);
        }

        return $app;
    }

    public function test_relative_and_absolute_values_resolve_against_the_app_directory(): void
    {
        $base = $this->root.'/home/inventoros';
        $web = realpath($this->root.'/home/public_html');

        $this->assertSame($web, PublicPath::resolve($base, '../public_html'));
        $this->assertSame($web, PublicPath::resolve($base, $web));
        $this->assertNull(PublicPath::resolve($base, '../missing'));
        $this->assertNull(PublicPath::resolve($base, ''));
    }

    public function test_the_env_value_is_relative_and_omitted_for_the_default_layout(): void
    {
        $base = $this->root.'/home/inventoros';

        $this->assertSame('../public_html', PublicPath::envValue($base, $this->root.'/home/public_html'));

        File::ensureDirectoryExists($base.'/public');
        $this->assertNull(PublicPath::envValue($base, $base.'/public'));
    }

    public function test_a_freshly_booted_console_app_uses_app_public_path(): void
    {
        $web = realpath($this->root.'/home/public_html');
        putenv("APP_PUBLIC_PATH={$web}");
        $_ENV['APP_PUBLIC_PATH'] = $_SERVER['APP_PUBLIC_PATH'] = $web;

        $app = $this->bootFreshConsoleApp();

        $this->assertSame($web, $app->publicPath());
        $this->assertSame($web.DIRECTORY_SEPARATOR.'plugin-assets', $app->publicPath('plugin-assets'));
    }

    public function test_an_explicit_public_path_from_the_front_controller_wins(): void
    {
        putenv('APP_PUBLIC_PATH='.realpath($this->root.'/home/public_html'));
        $_ENV['APP_PUBLIC_PATH'] = $_SERVER['APP_PUBLIC_PATH'] = realpath($this->root.'/home/public_html');

        $app = $this->bootFreshConsoleApp($this->root.'/home/inventoros');

        $this->assertSame($this->root.'/home/inventoros', $app->publicPath());
    }

    public function test_the_installer_records_the_web_root_of_a_split_install(): void
    {
        $envFile = $this->root.'/installer.env';
        file_put_contents($envFile, "APP_NAME=Inventoros\nDB_CONNECTION=sqlite\n");
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

        $this->app->usePublicPath($this->root.'/home/public_html');

        $this->postJson('/install/database/install', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'inventoros',
            'username' => 'inventoros',
            'password' => 'secret',
        ]);

        $env = (string) file_get_contents($envFile);
        $this->assertMatchesRegularExpression('/^APP_PUBLIC_PATH="(.+)"$/m', $env);
        preg_match('/^APP_PUBLIC_PATH="(.+)"$/m', $env, $m);
        $this->assertSame(realpath($this->root.'/home/public_html'), PublicPath::resolve(base_path(), $m[1]));
    }

    public function test_the_installer_does_not_record_the_default_web_root(): void
    {
        $envFile = $this->root.'/installer.env';
        file_put_contents($envFile, "APP_NAME=Inventoros\nDB_CONNECTION=sqlite\n");
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

        $this->postJson('/install/database/install', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'inventoros',
            'username' => 'inventoros',
            'password' => 'secret',
        ]);

        $this->assertStringNotContainsString('APP_PUBLIC_PATH', (string) file_get_contents($envFile));
    }
}
