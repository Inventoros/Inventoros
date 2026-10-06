<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Update\BackupService;
use App\Services\Update\DatabaseBackupFailedException;
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
        $backups->shouldReceive('lastDatabaseBackupMethod')->andReturn('pg_dump');
        $files->shouldReceive('downloadRelease')->once()->andReturn('/tmp/update.zip');
        $files->shouldReceive('verifyArchiveSignature')->once();
        $files->shouldReceive('extractZip')->once()->andReturn('/tmp/extracted');
        $files->shouldReceive('validateRelease')->once()->andReturn(['version' => '9.9.9', 'layout' => 'cpanel-v1']);
        $files->shouldReceive('installRelease')->once()->andThrow(new \RuntimeException('disk full'));

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
        // Said once, not "Update failed: Update failed and ...".
        $this->assertSame(
            'Update failed and the previous version was restored: disk full',
            $result['message'],
        );
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
        $backups->shouldReceive('lastDatabaseBackupMethod')->andReturn('pg_dump');
        $files->shouldReceive('downloadRelease')->once()->andReturn('/tmp/update.zip');
        $files->shouldReceive('verifyArchiveSignature')->once();
        $files->shouldReceive('extractZip')->once()->andReturn('/tmp/extracted');
        $files->shouldReceive('validateRelease')->once()->andReturn(['version' => '9.9.9', 'layout' => 'cpanel-v1']);
        $files->shouldReceive('installRelease')->once(); // files install fine; migration is what fails
        $files->shouldReceive('writeVersion')->never();
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

    public function test_a_failing_up_does_not_hide_why_the_update_failed(): void
    {
        Artisan::shouldReceive('call')->andReturn(0)->byDefault();
        Artisan::shouldReceive('call')->with('up')->andThrow(new \RuntimeException('cannot bring app up'));

        $github = Mockery::mock(GitHubReleaseService::class);
        $backups = Mockery::mock(BackupService::class);
        $files = Mockery::mock(FileUpdateService::class);

        $backups->shouldReceive('createBackup')->once()->andReturn('/tmp/backup_test.zip');
        $backups->shouldReceive('lastDatabaseBackupMethod')->andReturn('pg_dump');
        $files->shouldReceive('downloadRelease')->once()->andReturn('/tmp/update.zip');
        $files->shouldReceive('verifyArchiveSignature')->once();
        $files->shouldReceive('extractZip')->once()->andReturn('/tmp/extracted');
        $files->shouldReceive('validateRelease')->once()->andReturn(['version' => '9.9.9', 'layout' => 'cpanel-v1']);
        $files->shouldReceive('installRelease')->once()->andThrow(new \RuntimeException('disk full'));

        $service = Mockery::mock(UpdateService::class, [$github, $backups, $files])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('restoreFromBackup')->once()->andReturn(['success' => true, 'message' => 'ok']);

        $result = $service->update('https://github.com/Inventoros/Inventoros/releases/download/v9.9.9/x.zip');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('disk full', $result['message']);
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

    public function test_update_reports_the_database_backup_method(): void
    {
        Artisan::shouldReceive('call')->andReturn(0);

        $github = Mockery::mock(GitHubReleaseService::class);
        $backups = Mockery::mock(BackupService::class);
        $files = Mockery::mock(FileUpdateService::class);

        $backups->shouldReceive('createBackup')->once()->andReturn('/tmp/backup_test.zip');
        $backups->shouldReceive('lastDatabaseBackupMethod')->andReturn('php-dump');
        $files->shouldReceive('downloadRelease')->once()->andReturn('/tmp/update.zip');
        $files->shouldReceive('verifyArchiveSignature')->once();
        $files->shouldReceive('extractZip')->once()->andReturn('/tmp/extracted');
        $files->shouldReceive('validateRelease')->once()->andReturn(['version' => '9.9.9']);
        $files->shouldReceive('installRelease')->once();
        $files->shouldReceive('writeVersion')->once();
        $files->shouldReceive('cleanup')->once();

        $messages = [];
        (new UpdateService($github, $backups, $files))->update(
            'https://github.com/Inventoros/Inventoros/releases/download/v9.9.9/x.zip',
            function (string $message) use (&$messages) {
                $messages[] = $message;
            }
        );

        $this->assertContains('Database backed up using php-dump', $messages);
    }

    public function test_update_refuses_to_proceed_when_the_database_backup_fails(): void
    {
        // Backup failure changes no live files/schema, so maintenance ends.
        Artisan::shouldReceive('call')->with('down', Mockery::any())->once()->andReturn(0);
        Artisan::shouldReceive('call')->with('up')->once()->andReturn(0);

        $github = Mockery::mock(GitHubReleaseService::class);
        $backups = Mockery::mock(BackupService::class);
        $files = Mockery::mock(FileUpdateService::class);

        $backups->shouldReceive('createBackup')->once()
            ->andThrow(new DatabaseBackupFailedException('Could not back up the database'));
        $files->shouldReceive('downloadRelease')->once()->andReturn('/tmp/update.zip');
        $files->shouldReceive('verifyArchiveSignature')->once();
        $files->shouldReceive('extractZip')->once()->andReturn('/tmp/extracted');
        $files->shouldReceive('validateRelease')->once()->andReturn(['version' => '9.9.9']);
        $files->shouldReceive('installRelease')->never();
        $files->shouldReceive('replaceFiles')->never();

        $result = (new UpdateService($github, $backups, $files))
            ->update('https://github.com/Inventoros/Inventoros/releases/download/v9.9.9/x.zip');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Could not back up the database', $result['message']);
    }

    public function test_backup_is_taken_after_download_validation_and_maintenance(): void
    {
        $events = [];
        Artisan::shouldReceive('call')->andReturnUsing(function (string $command) use (&$events) {
            $events[] = $command;

            return 0;
        });

        $github = Mockery::mock(GitHubReleaseService::class);
        $backups = Mockery::mock(BackupService::class);
        $files = Mockery::mock(FileUpdateService::class);
        $files->shouldReceive('downloadRelease')->once()->andReturnUsing(function () use (&$events) {
            $events[] = 'download';

            return '/tmp/update.zip';
        });
        $files->shouldReceive('verifyArchiveSignature')->once();
        $files->shouldReceive('extractZip')->once()->andReturn('/tmp/extracted');
        $files->shouldReceive('validateRelease')->once()->andReturnUsing(function () use (&$events) {
            $events[] = 'validate';

            return ['version' => '9.9.9'];
        });
        $backups->shouldReceive('createBackup')->once()->andReturnUsing(function () use (&$events) {
            $events[] = 'backup';

            return '/tmp/backup.zip';
        });
        $backups->shouldReceive('lastDatabaseBackupMethod')->andReturn('php-dump');
        $files->shouldReceive('installRelease')->once();
        $files->shouldReceive('writeVersion')->once();
        $files->shouldReceive('cleanup')->once();

        $result = (new UpdateService($github, $backups, $files))->update('https://github.com/Inventoros/Inventoros/releases/download/v9.9.9/x.zip');

        $this->assertTrue($result['success']);
        $this->assertSame(['download', 'validate', 'down', 'backup', 'optimize:clear', 'migrate', 'optimize', 'queue:restart', 'up'], $events);
    }

    public function test_failed_rollback_keeps_the_application_in_maintenance(): void
    {
        Artisan::shouldReceive('call')->with('down', Mockery::any())->once()->andReturn(0);
        Artisan::shouldReceive('call')->with('up')->never();

        $github = Mockery::mock(GitHubReleaseService::class);
        $backups = Mockery::mock(BackupService::class);
        $files = Mockery::mock(FileUpdateService::class);
        $backups->shouldReceive('createBackup')->once()->andReturn('/tmp/backup.zip');
        $backups->shouldReceive('lastDatabaseBackupMethod')->andReturn('php-dump');
        $files->shouldReceive('downloadRelease')->once()->andReturn('/tmp/update.zip');
        $files->shouldReceive('verifyArchiveSignature')->once();
        $files->shouldReceive('extractZip')->once()->andReturn('/tmp/extracted');
        $files->shouldReceive('validateRelease')->once()->andReturn(['version' => '9.9.9']);
        $files->shouldReceive('installRelease')->once()->andThrow(new \RuntimeException('disk full'));

        $service = Mockery::mock(UpdateService::class, [$github, $backups, $files])->makePartial();
        $service->shouldReceive('restoreFromBackup')->once()->andReturn(['success' => false, 'message' => 'database restore failed']);

        $result = $service->update('https://github.com/Inventoros/Inventoros/releases/download/v9.9.9/x.zip');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('remains in maintenance mode', $result['message']);
        $this->assertStringContainsString('database restore failed', $result['message']);
    }
}
