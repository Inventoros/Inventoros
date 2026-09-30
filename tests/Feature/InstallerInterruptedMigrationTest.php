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
 * A full migration takes longer than PHP's default 30 second limit on many
 * hosts. When the limit killed the installer in the middle of a MySQL
 * migration (MySQL cannot roll back schema changes), the tables that
 * migration had created stayed behind unrecorded, and every retry failed
 * with "Table already exists". The installer now lifts the time limit, and
 * when the database holds an unfinished installation it says so and offers to
 * reset it.
 */
final class InstallerInterruptedMigrationTest extends TestCase
{
    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envFile = storage_path('app/testing-installer/installer.env');
        File::ensureDirectoryExists(dirname($this->envFile));
        file_put_contents($this->envFile, "APP_NAME=Inventoros\nDB_CONNECTION=sqlite\n");

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

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'inventoros',
            'username' => 'inventoros',
            'password' => 'secret',
        ] + $extra;
    }

    /**
     * Leave the database as a timeout in the middle of the last migration
     * that creates a table would: its table exists, its row in `migrations`
     * does not.
     */
    private function interruptLastTableMigration(): string
    {
        Artisan::call('migrate', ['--force' => true]);

        $migration = collect(File::files(database_path('migrations')))
            ->map(fn ($file) => $file->getFilenameWithoutExtension())
            ->sort()
            ->last(fn (string $name) => str_contains((string) File::get(database_path("migrations/{$name}.php")), 'Schema::create('));

        DB::table('migrations')->where('migration', '>=', $migration)->delete();

        return $migration;
    }

    public function test_a_fresh_database_installs(): void
    {
        $this->postJson('/install/database/install', $this->payload())
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue(Schema::hasTable('system_settings'));
    }

    public function test_an_unfinished_installation_is_reported_with_a_reset_option(): void
    {
        $this->interruptLastTableMigration();

        $this->postJson('/install/database/install', $this->payload())
            ->assertStatus(409)
            ->assertJson(['success' => false, 'can_reset' => true]);
    }

    public function test_resetting_an_unfinished_installation_completes_it(): void
    {
        $migration = $this->interruptLastTableMigration();
        DB::table('system_settings')->insert(['key' => 'leftover', 'value' => 'x', 'type' => 'string']);

        $this->postJson('/install/database/install', $this->payload(['reset_database' => true]))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue(DB::table('migrations')->where('migration', $migration)->exists());
        $this->assertFalse(DB::table('system_settings')->where('key', 'leftover')->exists(), 'A reset starts from empty tables.');
    }

    public function test_a_reset_is_never_offered_once_installed(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        DB::table('system_settings')->insert(['key' => 'installed', 'value' => '1', 'type' => 'boolean']);

        $this->postJson('/install/database/install', $this->payload(['reset_database' => true]))
            ->assertForbidden();

        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_the_time_limit_is_lifted_before_migrating(): void
    {
        $this->assertStringContainsString(
            'set_time_limit(0)',
            (string) File::get(app_path('Http/Controllers/Install/InstallerController.php')),
        );
    }
}
