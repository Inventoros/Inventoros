<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Update\BackupService;
use App\Services\Update\FileUpdateService;
use App\Services\Update\GitHubReleaseService;
use App\Services\UpdateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;
use ZipArchive;

final class UpdateServiceRestoreTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/testing'));
        Mockery::close();

        parent::tearDown();
    }

    private function makeBackupZip(bool $withDatabase): string
    {
        $path = storage_path('app/testing/restore_'.uniqid().'.zip');
        File::ensureDirectoryExists(dirname($path));

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('app/Foo.php', '<?php // foo');
        if ($withDatabase) {
            $zip->addFromString('database.sql', '-- dump');
        }
        $zip->close();

        return $path;
    }

    /**
     * @return array{0: UpdateService, 1: Mockery\MockInterface}
     */
    private function service(): array
    {
        Artisan::shouldReceive('call')->andReturn(0);

        $github = Mockery::mock(GitHubReleaseService::class);
        $backups = Mockery::mock(BackupService::class);
        $files = Mockery::mock(FileUpdateService::class);

        $files->shouldReceive('getTempPath')->andReturn(storage_path('app/testing'));
        $files->shouldReceive('replaceFiles')->once();

        return [new UpdateService($github, $backups, $files), $backups];
    }

    public function test_restore_imports_the_database_dump_when_the_backup_contains_one(): void
    {
        [$service, $backups] = $this->service();
        $backups->shouldReceive('importDatabaseDump')->once()->andReturn(true);

        $result = $service->restoreFromBackup($this->makeBackupZip(withDatabase: true));

        $this->assertTrue($result['success']);
    }

    public function test_restore_does_not_import_when_the_backup_has_no_database_dump(): void
    {
        [$service, $backups] = $this->service();
        $backups->shouldReceive('importDatabaseDump')->never();

        $result = $service->restoreFromBackup($this->makeBackupZip(withDatabase: false));

        $this->assertTrue($result['success']);
    }
}
