<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Update\BackupService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

final class BackupServiceTest extends TestCase
{
    private string $root;

    private string $base;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('app/testing/backup_'.uniqid());
        $this->base = $this->root.'/base';
        $this->backupDir = $this->base.'/storage/app/backups';

        // A minimal fake app tree to back up.
        File::makeDirectory($this->base.'/app', 0755, true, true);
        File::put($this->base.'/app/Foo.php', '<?php // foo');

        // A pre-existing backup living inside the backup dir. Backing up
        // "storage" must NOT recurse into it, or every backup would embed all
        // prior backups and grow without bound.
        File::makeDirectory($this->backupDir, 0755, true, true);
        File::put($this->backupDir.'/backup_old.zip', 'PRIOR-BACKUP-CONTENT');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/testing'));

        parent::tearDown();
    }

    private function service(): BackupService
    {
        return new class($this->backupDir, $this->base) extends BackupService
        {
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
                return ['app', 'storage'];
            }

            protected function backupDatabase(string $outputPath): bool
            {
                File::put($outputPath, '-- fake dump');

                return true;
            }
        };
    }

    public function test_backup_excludes_prior_backups_folds_in_the_db_dump_and_cleans_up(): void
    {
        $zipPath = $this->service()->createBackup();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        // The app tree is captured.
        $this->assertContains('app/Foo.php', $names);

        // The database dump is folded INTO the archive so a restore can import
        // it (previously it was written next to the zip and never included).
        $this->assertContains('database.sql', $names);

        // Prior backups are not recursively embedded.
        foreach ($names as $name) {
            $this->assertStringNotContainsString('backup_old.zip', $name);
        }

        // The loose dump is removed once it is inside the archive.
        $this->assertSame([], glob($this->backupDir.'/*.sql'));
    }

    public function test_backup_skips_files_that_resolve_to_no_real_path(): void
    {
        // A broken symlink resolves getRealPath() to false. The backup must
        // skip it rather than passing false to the string-typed exclusion
        // check and aborting the entire backup with a TypeError.
        $link = $this->base.'/app/broken-link';
        if (@symlink($this->base.'/app/does-not-exist', $link) === false) {
            $this->markTestSkipped('Symlinks are not supported in this environment.');
        }

        $zipPath = $this->service()->createBackup();

        $this->assertFileExists($zipPath);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        // The valid file is still captured; the backup did not abort.
        $this->assertContains('app/Foo.php', $names);
    }

    public function test_import_database_dump_skips_non_mysql_drivers(): void
    {
        // The suite runs on sqlite, whose data is restored via file replacement,
        // so a SQL import is a graceful no-op rather than an error.
        $dump = $this->root.'/database.sql';
        File::makeDirectory($this->root, 0755, true, true);
        File::put($dump, '-- noop');

        $this->assertFalse((new BackupService($this->backupDir))->importDatabaseDump($dump));
    }

    public function test_mysql_import_passes_the_password_via_env_not_the_command_line(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'mysql',
                'username' => 'dbuser',
                'password' => 'sup3r-secret-pw',
                'host' => '127.0.0.1',
                'database' => 'inventoros',
            ],
        ]);

        $dump = $this->root.'/database.sql';
        File::makeDirectory($this->root, 0755, true, true);
        File::put($dump, '-- dump');

        $service = new class($this->backupDir) extends BackupService
        {
            public ?string $captured = null;

            public ?string $pwdDuringRun = null;

            protected function runCommand(string $command): int
            {
                $this->captured = $command;
                $this->pwdDuringRun = getenv('MYSQL_PWD') ?: null;

                return 0;
            }
        };

        $this->assertTrue($service->importDatabaseDump($dump));

        // The password never appears on the command line (process list).
        $this->assertStringNotContainsString('--password', (string) $service->captured);
        $this->assertStringNotContainsString('sup3r-secret-pw', (string) $service->captured);

        // It is supplied to the child process via MYSQL_PWD instead, and the
        // env var is cleaned up afterwards.
        $this->assertSame('sup3r-secret-pw', $service->pwdDuringRun);
        $this->assertFalse(getenv('MYSQL_PWD'));
    }
}
