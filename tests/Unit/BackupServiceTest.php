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

            protected function backupDatabase(string $outputBase): ?array
            {
                File::put($outputBase.'.sql', '-- fake dump');

                return ['driver' => 'sqlite', 'method' => 'php-dump', 'path' => $outputBase.'.sql', 'file' => 'database.sql'];
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

    public function test_restore_refuses_a_legacy_mysql_dump_on_another_driver(): void
    {
        // A manifest-less backup with database.sql came from mysqldump. On a
        // SQLite install that dump cannot be replayed, and silently skipping
        // it would report a restore that never touched the data.
        config(['database.connections.legacy_probe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['database.default' => 'legacy_probe']);

        $dir = $this->root.'/extract';
        File::makeDirectory($dir, 0755, true, true);
        File::put($dir.'/database.sql', '-- dump');

        $this->expectException(\RuntimeException::class);
        (new BackupService($this->backupDir))->restoreDatabase($dir);
    }
}
