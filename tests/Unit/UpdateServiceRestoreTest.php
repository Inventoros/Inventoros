<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Update\BackupService;
use App\Services\Update\FileUpdateService;
use App\Services\Update\GitHubReleaseService;
use App\Services\UpdateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
        Artisan::shouldReceive('call')->andReturn(0)->byDefault();

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
        $backups->shouldReceive('restoreDatabase')->once()->andReturn('php-dump');

        $result = $service->restoreFromBackup($this->makeBackupZip(withDatabase: true));

        $this->assertTrue($result['success']);
    }

    public function test_restore_does_not_import_when_the_backup_has_no_database_dump(): void
    {
        [$service, $backups] = $this->service();
        $backups->shouldReceive('restoreDatabase')->once()->andReturn(null);

        $result = $service->restoreFromBackup($this->makeBackupZip(withDatabase: false));

        $this->assertTrue($result['success']);
    }

    public function test_restore_fails_loudly_when_the_database_cannot_be_restored(): void
    {
        [$service, $backups] = $this->service();
        Artisan::shouldReceive('call')->with('up')->never();
        $backups->shouldReceive('restoreDatabase')->once()->andThrow(new \RuntimeException('psql exited with code 3'));

        $result = $service->restoreFromBackup($this->makeBackupZip(withDatabase: true));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('psql exited with code 3', $result['message']);
        $this->assertStringContainsString('remains in maintenance mode', $result['message']);
    }

    public function test_file_restore_failure_keeps_the_application_in_maintenance(): void
    {
        Artisan::shouldReceive('call')->with('down')->once()->andReturn(0);
        Artisan::shouldReceive('call')->with('up')->never();
        $files = Mockery::mock(FileUpdateService::class);
        $files->shouldReceive('getTempPath')->andReturn(storage_path('app/testing'));
        $files->shouldReceive('replaceFiles')->once()->andThrow(new \RuntimeException('disk full'));
        $backups = Mockery::mock(BackupService::class);
        $backups->shouldReceive('restoreDatabase')->never();

        $result = (new UpdateService(Mockery::mock(GitHubReleaseService::class), $backups, $files))
            ->restoreFromBackup($this->makeBackupZip(withDatabase: true));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('remains in maintenance mode', $result['message']);
    }

    public function test_a_restore_holds_the_update_lock_so_the_temp_sweep_cannot_remove_its_files(): void
    {
        Artisan::shouldReceive('call')->andReturn(0)->byDefault();
        $files = Mockery::mock(FileUpdateService::class);
        $files->shouldReceive('getTempPath')->andReturn(storage_path('app/testing'));
        $backups = Mockery::mock(BackupService::class);
        $backups->shouldReceive('restoreDatabase')->once()->andReturn('php-dump');

        $service = new UpdateService(Mockery::mock(GitHubReleaseService::class), $backups, $files);

        $files->shouldReceive('replaceFiles')->once()->andReturnUsing(function () use ($service) {
            // update:clean-temp (and a second update or restore) is refused
            // while the backup is being restored.
            $this->assertNull($service->cleanTempFiles());
            $this->assertFalse(Cache::lock(UpdateService::LOCK, 10)->get());
        });
        $files->shouldReceive('removeTempArtifacts')->never();

        $this->assertTrue($service->restoreFromBackup($this->makeBackupZip(withDatabase: true))['success']);

        // Released afterwards.
        $lock = Cache::lock(UpdateService::LOCK, 10);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_a_restore_is_refused_while_an_update_or_another_restore_runs(): void
    {
        Artisan::shouldReceive('call')->never();
        $files = Mockery::mock(FileUpdateService::class);
        $files->shouldReceive('replaceFiles')->never();
        $backups = Mockery::mock(BackupService::class);
        $backups->shouldReceive('restoreDatabase')->never();

        $lock = Cache::lock(UpdateService::LOCK, 60);
        $this->assertTrue($lock->get());

        try {
            $result = (new UpdateService(Mockery::mock(GitHubReleaseService::class), $backups, $files))
                ->restoreFromBackup($this->makeBackupZip(withDatabase: true));
        } finally {
            $lock->release();
        }

        $this->assertFalse($result['success']);
        $this->assertSame('update_in_progress', $result['error']);
    }
}
