<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Install\InstallerController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The shipped .env.example says DB_CONNECTION=sqlite. The database step wrote
 * the MySQL settings to .env but kept migrating over the connection the
 * request had booted with, so a real install migrated into a new
 * database/database.sqlite, reported success, left MySQL empty and failed on
 * the admin step ("Table 'inventoros.users' doesn't exist"). The installer
 * now switches the running request to the database it was given, migrates
 * that one and checks the tables are there.
 */
final class InstallerTargetConnectionTest extends TestCase
{
    private string $dir;

    private string $shippedDatabase;

    private string $chosenDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('app/testing-installer-target');
        File::ensureDirectoryExists($this->dir);
        file_put_contents($this->dir.'/installer.env', "APP_NAME=Inventoros\nDB_CONNECTION=sqlite\n");
        touch($this->shippedDatabase = $this->dir.'/shipped.sqlite');
        touch($this->chosenDatabase = $this->dir.'/chosen.sqlite');

        // The request boots on the shipped default: SQLite.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->shippedDatabase,
        ]);
        DB::purge('sqlite');

        $dir = $this->dir;
        $chosen = $this->chosenDatabase;
        $this->app->bind(InstallerController::class, fn () => new class($dir, $chosen) extends InstallerController
        {
            public function __construct(private string $testDir, private string $chosenFile) {}

            protected function envFilePath(): string
            {
                return $this->testDir.'/installer.env';
            }

            /**
             * The database the user picked, as a second SQLite file: the
             * connection keeps the chosen driver's name, only its settings
             * point elsewhere.
             */
            protected function connectionSettings(string $driver, array $input): array
            {
                return [
                    'driver' => 'sqlite',
                    'database' => $this->chosenFile,
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ];
            }
        });
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        DB::purge('pgsql');
        DB::purge('sqlite');
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $driver = 'mysql'): array
    {
        return [
            'driver' => $driver,
            'host' => 'db.example.test',
            'port' => $driver === 'pgsql' ? 5432 : 3306,
            'database' => 'inventoros',
            'username' => 'inventoros',
            'password' => 'secret',
        ];
    }

    /**
     * @return list<string>
     */
    private function tablesIn(string $file): array
    {
        $pdo = new \PDO('sqlite:'.$file);

        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function test_the_tables_are_created_in_the_chosen_database_not_the_default_one(): void
    {
        $this->postJson('/install/database/install', $this->payload())
            ->assertOk()
            ->assertJson(['success' => true]);

        $chosen = $this->tablesIn($this->chosenDatabase);
        $this->assertContains('migrations', $chosen);
        $this->assertContains('users', $chosen);
        $this->assertContains('system_settings', $chosen);
        $this->assertSame([], $this->tablesIn($this->shippedDatabase), 'Nothing may be migrated into the connection the request booted with.');
        $this->assertSame('mysql', config('database.default'));
    }

    public function test_a_postgresql_install_also_switches_the_default_connection(): void
    {
        $this->postJson('/install/database/install', $this->payload('pgsql'))->assertOk();

        $this->assertSame('pgsql', config('database.default'));
        $this->assertContains('migrations', $this->tablesIn($this->chosenDatabase));
        $this->assertSame([], $this->tablesIn($this->shippedDatabase));
    }

    public function test_the_installer_fails_loudly_when_the_chosen_database_has_no_tables_afterwards(): void
    {
        // Migrating "succeeds" without creating anything, as it did when it
        // ran against the wrong connection.
        Artisan::shouldReceive('call')->andReturn(0);

        $this->postJson('/install/database/install', $this->payload())
            ->assertStatus(500)
            ->assertJson(['success' => false, 'message_key' => 'install.database.installFailed']);
    }

    public function test_the_connection_is_built_from_the_submitted_settings(): void
    {
        // Boot-time values, as a cached config or the shipped .env leaves them.
        config([
            'database.connections.mysql.host' => 'stale-host',
            'database.connections.mysql.database' => 'laravel',
            'database.connections.mysql.url' => 'mysql://root@stale-host/laravel',
            'database.connections.pgsql.host' => 'stale-host',
        ]);

        $installer = new class extends InstallerController
        {
            /** @return array<string, mixed> */
            public function settingsFor(string $driver, array $input): array
            {
                return $this->connectionSettings($driver, $input);
            }
        };

        foreach (['mysql' => 3306, 'pgsql' => 5432] as $driver => $port) {
            $settings = $installer->settingsFor($driver, [
                'host' => 'db.example.test',
                'port' => $port,
                'database' => 'inventoros',
                'username' => 'inventoros',
                'password' => 'p@ss$word',
            ]);

            $this->assertSame($driver, $settings['driver']);
            $this->assertSame('db.example.test', $settings['host']);
            $this->assertSame((string) $port, (string) $settings['port']);
            $this->assertSame('inventoros', $settings['database']);
            $this->assertSame('inventoros', $settings['username']);
            $this->assertSame('p@ss$word', $settings['password']);
            $this->assertNull($settings['url'], 'A DB_URL would override every other setting.');
            $this->assertSame(config("database.connections.{$driver}.charset"), $settings['charset'], 'The driver\'s other settings are kept.');
        }
    }
}
