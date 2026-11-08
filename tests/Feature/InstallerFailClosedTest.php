<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Install\InstallerController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class InstallerFailClosedTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-guard-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/installer.env', "APP_NAME=Inventoros\nDB_HOST=original\n");
        config([
            'app.installed' => false,
            'database.default' => 'installer_guard',
            'database.connections.installer_guard' => [
                'driver' => 'sqlite',
                // An existing directory cannot be opened as a SQLite database:
                // a real connection failure, without any network dependency.
                'database' => $this->directory,
                'prefix' => '',
            ],
        ]);

        $envFile = $this->directory.'/installer.env';
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
        DB::purge('installer_guard');
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_a_legacy_database_outage_cannot_open_the_installer_or_reconfigure_the_database(): void
    {
        $before = file_get_contents($this->directory.'/installer.env');

        $this->get('/install')->assertStatus(503);
        $this->get('/login')->assertStatus(503);
        $this->postJson('/install/database/test', [])->assertStatus(503);
        $this->postJson('/install/database/install', [
            'driver' => 'mysql',
            'host' => 'attacker.example',
            'port' => 3306,
            'database' => 'hijacked',
            'username' => 'attacker',
            'reset_database' => true,
        ])->assertStatus(503);
        $this->postJson('/install/admin', [])->assertStatus(503);

        $this->assertSame($before, file_get_contents($this->directory.'/installer.env'));
        $this->assertSame('array', config('session.driver'));
    }

    public function test_the_durable_marker_closes_setup_without_a_working_database(): void
    {
        config(['app.installed' => true]);

        $this->get('/install')->assertRedirect('/login');
        $this->postJson('/install/database/install', [])->assertForbidden();
        $this->postJson('/install/admin', [])->assertForbidden();
    }

    public function test_existing_legacy_accounts_keep_setup_closed_if_the_database_setting_is_missing(): void
    {
        $database = $this->directory.'/legacy.sqlite';
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO users (id) VALUES (1)');
        $pdo = null;
        config(['database.connections.installer_guard.database' => $database]);
        DB::purge('installer_guard');

        $this->postJson('/install/database/install', [])->assertForbidden();
        $this->postJson('/install/admin', [])->assertForbidden();
    }

    public function test_a_missing_production_sqlite_database_does_not_reopen_setup(): void
    {
        config([
            'app.env' => 'production',
            'database.connections.installer_guard.database' => $this->directory.'/missing.sqlite',
        ]);
        DB::purge('installer_guard');

        $this->get('/install')->assertStatus(503);
        $this->postJson('/install/database/install', [])->assertStatus(503);
    }

    public function test_controller_checks_also_fail_closed_without_the_installation_middleware(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        // Prevent a future controller-only code path from reviving the old
        // catch-and-return-false behavior.
        $this->app->make(InstallerController::class)->index();
    }
}
