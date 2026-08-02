<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Update\BackupService;
use App\Services\Update\FileUpdateService;
use App\Services\Update\GitHubReleaseService;
use App\Services\UpdateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

final class UpdateServiceRollbackTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_failed_file_replacement_triggers_restore_and_returns_failure(): void
    {
        // Neutralize all artisan side effects (down/up/migrate/optimize).
        Artisan::shouldReceive('call')->andReturn(0);

        $github = Mockery::mock(GitHubReleaseService::class);
        $backups = Mockery::mock(BackupService::class);
        $files = Mockery::mock(FileUpdateService::class);

        $backups->shouldReceive('createBackup')->once()->andReturn('/tmp/backup_test.zip');
        $files->shouldReceive('downloadRelease')->once()->andReturn('/tmp/update.zip');
        $files->shouldReceive('verifyArchiveSignature')->once();
        $files->shouldReceive('extractZip')->once()->andReturn('/tmp/extracted');
        $files->shouldReceive('replaceFiles')->once()->andThrow(new \RuntimeException('disk full'));

        $service = Mockery::mock(UpdateService::class, [$github, $backups, $files])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('restoreFromBackup')
            ->once()
            ->with('/tmp/backup_test.zip')
            ->andReturn(['success' => true, 'message' => 'Backup restored successfully']);

        $result = $service->update('https://github.com/Inventoros/Inventoros/releases/download/v9.9.9/x.zip');

        $this->assertFalse($result['success']);
        $this->assertStringContainsStringIgnoringCase('restored', $result['message']);
    }

    public function test_failed_migration_triggers_restore_and_returns_failure(): void
    {
        // A migration failure leaves the new application files running against the
        // old schema. The updater must restore the backup (files + DB), not merely
        // log the error and bring the app back up on a broken schema.
        Artisan::shouldReceive('call')->andReturn(0)->byDefault();
        Artisan::shouldReceive('call')
            ->with('migrate', Mockery::any())
            ->andThrow(new \RuntimeException('migration boom'));

        $github = Mockery::mock(GitHubReleaseService::class);
        $backups = Mockery::mock(BackupService::class);
        $files = Mockery::mock(FileUpdateService::class);

        $backups->shouldReceive('createBackup')->once()->andReturn('/tmp/backup_test.zip');
        $files->shouldReceive('downloadRelease')->once()->andReturn('/tmp/update.zip');
        $files->shouldReceive('verifyArchiveSignature')->once();
        $files->shouldReceive('extractZip')->once()->andReturn('/tmp/extracted');
        $files->shouldReceive('replaceFiles')->once(); // files replace fine; migration is what fails
        $files->shouldReceive('cleanup')->never();     // must not clean up after a failed apply

        $service = Mockery::mock(UpdateService::class, [$github, $backups, $files])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('restoreFromBackup')
            ->once()
            ->with('/tmp/backup_test.zip')
            ->andReturn(['success' => true, 'message' => 'Backup restored successfully']);

        $result = $service->update('https://github.com/Inventoros/Inventoros/releases/download/v9.9.9/x.zip');

        $this->assertFalse($result['success']);
        $this->assertStringContainsStringIgnoringCase('restored', $result['message']);
    }

    public function test_update_is_blocked_when_another_update_holds_the_lock(): void
    {
        // A concurrent update must fail fast without touching the installation.
        $held = Cache::lock('inventoros:update', 900);
        $this->assertTrue($held->get());

        try {
            $github = Mockery::mock(GitHubReleaseService::class);
            $backups = Mockery::mock(BackupService::class);
            $files = Mockery::mock(FileUpdateService::class);
            $backups->shouldReceive('createBackup')->never();
            $files->shouldReceive('downloadRelease')->never();

            $service = new UpdateService($github, $backups, $files);
            $result = $service->update('https://github.com/Inventoros/Inventoros/releases/download/v9.9.9/x.zip');

            $this->assertFalse($result['success']);
            $this->assertSame('update_in_progress', $result['error']);
        } finally {
            $held->release();
        }
    }
}
