<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Update\BackupService;
use App\Services\Update\DatabaseBackupFailedException;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Driver dispatch for database backups/restores, exercised through the
 * runCommand()/canExec()/phpDump() seams so no real mysqldump/pg_dump or
 * remote database is needed.
 */
final class BackupDatabaseDispatchTest extends TestCase
{
    private string $root;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('app/testing/dispatch_'.uniqid());
        $this->backupDir = $this->root.'/backups';
        File::makeDirectory($this->backupDir, 0755, true, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/testing'));
        putenv('PGPASSWORD');
        putenv('MYSQL_PWD');

        parent::tearDown();
    }

    private function usePgsql(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql' => [
                'driver' => 'pgsql',
                'username' => 'pguser',
                'password' => 'pg-s3cret',
                'host' => 'db.internal',
                'port' => '5433',
                'database' => 'inventoros',
            ],
        ]);
    }

    private function useMysql(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'mysql',
                'username' => 'dbuser',
                'password' => 'my-s3cret',
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'inventoros',
            ],
        ]);
    }

    /**
     * A service whose external effects are all captured: shell commands,
     * exec availability and the pure-PHP dumper.
     */
    private function fakeService(bool $canExec = true, int $exitCode = 0, bool $phpDumpWorks = true): BackupService
    {
        return new class($this->backupDir, $canExec, $exitCode, $phpDumpWorks) extends BackupService
        {
            /** @var array<int, array{command: string, pgpassword: string|false, mysqlpwd: string|false}> */
            public array $commands = [];

            public int $phpDumps = 0;

            public int $phpRestores = 0;

            public function __construct(string $dir, private bool $execOk, private int $exitCode, private bool $phpDumpWorks)
            {
                parent::__construct($dir);
            }

            protected function basePath(): string
            {
                return $this->getBackupPath().'/../base';
            }

            protected function itemsToBackup(): array
            {
                return [];
            }

            protected function canExec(): bool
            {
                return $this->execOk;
            }

            protected function runCommand(string $command): int
            {
                $this->commands[] = [
                    'command' => $command,
                    'pgpassword' => getenv('PGPASSWORD'),
                    'mysqlpwd' => getenv('MYSQL_PWD'),
                ];

                // Simulate the dump tool writing its output file.
                if ($this->exitCode === 0 && preg_match("/--file=('[^']+'|\"[^\"]+\")/", $command, $m)) {
                    File::put(trim($m[1], "'\""), '-- native dump');
                }
                if ($this->exitCode === 0 && preg_match("/ > ('[^']+'|\"[^\"]+\")$/", $command, $m)) {
                    File::put(trim($m[1], "'\""), '-- native dump');
                }

                return $this->exitCode;
            }

            protected function phpDump(string $outputPath): void
            {
                $this->phpDumps++;

                if (! $this->phpDumpWorks) {
                    throw new \RuntimeException('connection refused');
                }

                File::put($outputPath, '-- php dump');
            }

            protected function phpRestore(string $sqlPath): void
            {
                $this->phpRestores++;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestOf(string $zipPath): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $manifest = json_decode((string) $zip->getFromName('backup-manifest.json'), true);
        $zip->close();

        return $manifest;
    }

    public function test_pgsql_backup_uses_pg_dump_with_the_password_in_the_environment(): void
    {
        $this->usePgsql();
        $service = $this->fakeService();

        $zip = $service->createBackup();

        $this->assertSame('pg_dump', $service->lastDatabaseBackupMethod());
        $this->assertCount(1, $service->commands);
        $run = $service->commands[0];
        $this->assertStringStartsWith('pg_dump ', $run['command']);
        $this->assertStringContainsString('db.internal', $run['command']);
        $this->assertStringContainsString('5433', $run['command']);
        $this->assertStringNotContainsString('pg-s3cret', $run['command']);
        $this->assertSame('pg-s3cret', $run['pgpassword']);
        $this->assertFalse(getenv('PGPASSWORD'));
        $this->assertSame(0, $service->phpDumps);

        $manifest = $this->manifestOf($zip);
        $this->assertSame('pgsql', $manifest['database']['driver']);
        $this->assertSame('pg_dump', $manifest['database']['method']);
        $this->assertSame('database.sql', $manifest['database']['file']);
    }

    public function test_mysql_backup_uses_mysqldump_with_the_password_in_the_environment(): void
    {
        $this->useMysql();
        $service = $this->fakeService();

        $service->createBackup();

        $this->assertSame('mysqldump', $service->lastDatabaseBackupMethod());
        $run = $service->commands[0];
        $this->assertStringStartsWith('mysqldump ', $run['command']);
        $this->assertStringNotContainsString('my-s3cret', $run['command']);
        $this->assertSame('my-s3cret', $run['mysqlpwd']);
    }

    public function test_falls_back_to_the_php_dump_when_the_dump_binary_fails(): void
    {
        $this->usePgsql();
        $service = $this->fakeService(exitCode: 127);

        $zip = $service->createBackup();

        $this->assertSame('php-dump', $service->lastDatabaseBackupMethod());
        $this->assertSame(1, $service->phpDumps);
        $this->assertSame('php-dump', $this->manifestOf($zip)['database']['method']);
    }

    public function test_falls_back_to_the_php_dump_when_exec_is_unavailable(): void
    {
        $this->useMysql();
        $service = $this->fakeService(canExec: false);

        $service->createBackup();

        $this->assertSame([], $service->commands);
        $this->assertSame('php-dump', $service->lastDatabaseBackupMethod());
    }

    public function test_backup_fails_loudly_when_no_database_backup_could_be_made(): void
    {
        $this->usePgsql();
        $service = $this->fakeService(canExec: false, phpDumpWorks: false);

        try {
            $service->createBackup();
            $this->fail('Expected the backup to refuse to proceed without a database dump.');
        } catch (DatabaseBackupFailedException $e) {
            $this->assertStringContainsString('INVENTOROS_UPDATE_ALLOW_NO_DB_BACKUP', $e->getMessage());
        }

        // No half-made archive is left behind to be mistaken for a good backup.
        $this->assertSame([], glob($this->backupDir.'/*.zip'));
    }

    public function test_the_override_allows_a_files_only_backup(): void
    {
        $this->usePgsql();
        config(['update.allow_missing_database_backup' => true]);
        $service = $this->fakeService(canExec: false, phpDumpWorks: false);

        $zip = $service->createBackup();

        $this->assertFileExists($zip);
        $this->assertSame('none', $service->lastDatabaseBackupMethod());
        $this->assertNull($this->manifestOf($zip)['database']);
    }

    public function test_pgsql_restore_uses_psql_for_a_pg_dump_backup(): void
    {
        $this->usePgsql();
        $service = $this->fakeService();
        $extract = $this->extracted(['driver' => 'pgsql', 'method' => 'pg_dump', 'file' => 'database.sql']);

        $this->assertSame('pg_dump', $service->restoreDatabase($extract));

        $run = $service->commands[0];
        $this->assertStringStartsWith('psql ', $run['command']);
        $this->assertStringContainsString('ON_ERROR_STOP=1', $run['command']);
        $this->assertStringContainsString('--single-transaction', $run['command']);
        $this->assertStringNotContainsString('pg-s3cret', $run['command']);
        $this->assertSame('pg-s3cret', $run['pgpassword']);
    }

    public function test_mysql_restore_uses_the_mysql_client_for_a_mysqldump_backup(): void
    {
        $this->useMysql();
        $service = $this->fakeService();
        $extract = $this->extracted(['driver' => 'mysql', 'method' => 'mysqldump', 'file' => 'database.sql']);

        $this->assertSame('mysqldump', $service->restoreDatabase($extract));
        $this->assertStringStartsWith('mysql ', $service->commands[0]['command']);
    }

    public function test_legacy_backup_without_a_manifest_is_restored_as_a_mysqldump(): void
    {
        $this->useMysql();
        $service = $this->fakeService();
        $extract = $this->extracted(null);

        $this->assertSame('mysqldump', $service->restoreDatabase($extract));
    }

    public function test_php_dump_backups_are_restored_in_php(): void
    {
        $this->usePgsql();
        $service = $this->fakeService();
        $extract = $this->extracted(['driver' => 'pgsql', 'method' => 'php-dump', 'file' => 'database.sql']);

        $this->assertSame('php-dump', $service->restoreDatabase($extract));
        $this->assertSame(1, $service->phpRestores);
        $this->assertSame([], $service->commands);
    }

    public function test_restore_fails_loudly_when_the_client_binary_fails(): void
    {
        $this->usePgsql();
        $service = $this->fakeService(exitCode: 3);
        $extract = $this->extracted(['driver' => 'pgsql', 'method' => 'pg_dump', 'file' => 'database.sql']);

        $this->expectException(\RuntimeException::class);
        $service->restoreDatabase($extract);
    }

    public function test_restore_refuses_a_backup_from_a_different_driver(): void
    {
        $this->usePgsql();
        $service = $this->fakeService();
        $extract = $this->extracted(['driver' => 'mysql', 'method' => 'mysqldump', 'file' => 'database.sql']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mysql');
        $service->restoreDatabase($extract);
    }

    public function test_restore_returns_null_when_the_backup_has_no_database(): void
    {
        $this->usePgsql();
        $dir = $this->root.'/extract_empty';
        File::makeDirectory($dir, 0755, true, true);

        $this->assertNull($this->fakeService()->restoreDatabase($dir));
    }

    /**
     * @param  array<string, string>|null  $database  Manifest database block; null writes a legacy (manifest-less) backup
     */
    private function extracted(?array $database): string
    {
        $dir = $this->root.'/extract_'.uniqid();
        File::makeDirectory($dir, 0755, true, true);
        File::put($dir.'/database.sql', '-- dump');

        if ($database !== null) {
            File::put($dir.'/backup-manifest.json', json_encode(['format' => 1, 'database' => $database]));
        }

        return $dir;
    }
}
