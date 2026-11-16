<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Update\BackupService;
use App\Services\UpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Backup archives hold .env and storage/, so they and the database dump are
 * readable by the owner only. Deleting a backup removes its own database
 * dump, wherever the backup directory lives.
 */
final class BackupArchiveHardeningTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        // The directory name itself contains "backup_".
        $this->root = storage_path('app/testing/backup_hardening_'.uniqid());
        File::makeDirectory($this->root, 0755, true, true);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStoragePath);
        File::deleteDirectory(dirname($this->root));

        parent::tearDown();
    }

    public function test_the_backup_archive_and_database_dump_are_readable_by_the_owner_only(): void
    {
        $base = $this->root.'/base';
        $backupDir = $base.'/storage/app/backups';
        File::makeDirectory($base.'/app', 0755, true, true);
        File::put($base.'/app/Foo.php', '<?php // foo');
        File::put($base.'/.env', 'APP_KEY=base64:secret');

        $service = new class($backupDir, $base) extends BackupService
        {
            public ?int $dumpMode = null;

            private ?string $dumpPath = null;

            public function __construct(string $backupPath, private string $fakeBase)
            {
                parent::__construct($backupPath);
            }

            protected function basePath(): string
            {
                return $this->fakeBase;
            }

            protected function itemsToBackup(): array
            {
                return ['app', '.env'];
            }

            protected function backupDatabase(string $outputBase): ?array
            {
                $this->dumpPath = $outputBase.'.sql';
                File::put($this->dumpPath, '-- fake dump');
                @chmod($this->dumpPath, 0644);

                return ['driver' => 'sqlite', 'method' => 'php-dump', 'path' => $this->dumpPath, 'file' => 'database.sql'];
            }

            // Runs after the dump exists and before it is folded into the
            // archive and removed: record its mode then.
            protected function addDirectoryToZip(ZipArchive $zip, string $path, string $zipPath, array $excludePaths = []): void
            {
                clearstatcache(true, (string) $this->dumpPath);
                $this->dumpMode = fileperms((string) $this->dumpPath) & 0777;

                parent::addDirectoryToZip($zip, $path, $zipPath, $excludePaths);
            }
        };

        $zipPath = $service->createBackup();

        $this->assertFileExists($zipPath);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertNotFalse($zip->locateName('.env'));
        $this->assertNotFalse($zip->locateName('database.sql'));
        $zip->close();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('chmod does not set POSIX permission bits on Windows.');
        }

        clearstatcache(true, $zipPath);
        $this->assertSame(0600, fileperms($zipPath) & 0777, 'backup archive mode');
        $this->assertSame(0600, $service->dumpMode, 'database dump mode');
    }

    public function test_deleting_a_backup_removes_its_dump_even_when_a_directory_name_contains_backup_(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        $organization = Organization::create(['name' => 'Owner', 'email' => 'owner@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
            'organization_id' => $organization->id, 'role' => 'admin',
        ]);
        $role = Role::firstOrCreate(['slug' => 'system-administrator'], [
            'name' => 'Administrator', 'is_system' => true, 'permissions' => ['manage_organization'],
        ]);
        $admin->roles()->syncWithoutDetaching([$role->id]);
        config(['plugins.admin_organization_id' => $organization->id]);

        $this->app->useStoragePath($this->root);
        $backups = $this->root.'/app/backups';
        File::makeDirectory($backups, 0755, true, true);
        File::put($backups.'/backup_2026-01-01_000000.zip', 'zip');
        File::put($backups.'/database_2026-01-01_000000.sql', 'dump');

        // Where replacing "backup_" across the whole path would point.
        $elsewhere = str_replace('backup_', 'database_', $backups.'/backup_2026-01-01_000000.zip');
        $elsewhere = str_replace('.zip', '.sql', $elsewhere);
        File::makeDirectory(dirname($elsewhere), 0755, true, true);
        File::put($elsewhere, 'unrelated file');

        $this->mock(UpdateService::class, fn ($mock) => $mock->shouldReceive('listBackups')->andReturn([
            ['filename' => 'backup_2026-01-01_000000.zip', 'path' => $backups.'/backup_2026-01-01_000000.zip', 'size' => 3, 'created_at' => time()],
        ]));

        $this->actingAs($admin)
            ->deleteJson(route('admin.update.backup.delete'), ['backup_file' => 'backup_2026-01-01_000000.zip'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertFileDoesNotExist($backups.'/backup_2026-01-01_000000.zip');
        $this->assertFileDoesNotExist($backups.'/database_2026-01-01_000000.sql');
        $this->assertFileExists($elsewhere);
    }
}
