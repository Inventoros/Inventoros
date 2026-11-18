<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Update\BackupService;
use App\Services\Update\FileUpdateService;
use App\Services\UpdateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * `php artisan update:clean-temp` and the stale-file sweep a backup runs:
 * only the updater's own leftovers in storage/app/temp are removed.
 */
final class CleanUpdateTempFilesCommandTest extends TestCase
{
    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temp = storage_path('app/testing/update_temp_'.uniqid());
        File::ensureDirectoryExists($this->temp);
        $this->app->instance(FileUpdateService::class, new FileUpdateService(null, null, $this->temp));

        File::ensureDirectoryExists($this->temp.'/extracted_1700000000_abcd1234/inventoros');
        File::put($this->temp.'/extracted_1700000000_abcd1234/inventoros/VERSION', '2.5.0');
        File::ensureDirectoryExists($this->temp.'/extracted_1700000000_final');
        File::put($this->temp.'/update_1700000000_abcd1234.zip', str_repeat('x', 2048));
        File::put($this->temp.'/signature_0123456789ab.sig', 'sig');
        File::ensureDirectoryExists($this->temp.'/restore_1700000000');
        File::put($this->temp.'/upload-0123456789abcdef.zip', 'a plugin upload in flight');
        File::put($this->temp.'/notes.txt', 'not the updater\'s');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/testing'));
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function left(): array
    {
        $names = array_values(array_diff(scandir($this->temp) ?: [], ['.', '..']));
        sort($names);

        return $names;
    }

    public function test_it_removes_every_update_leftover_and_nothing_else(): void
    {
        $this->artisan('update:clean-temp')
            ->expectsOutputToContain('deleted update_1700000000_abcd1234.zip')
            ->expectsOutputToContain('Deleted 5 update temp entries')
            ->assertSuccessful();

        $this->assertSame(['notes.txt', 'upload-0123456789abcdef.zip'], $this->left());
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $before = $this->left();

        $this->artisan('update:clean-temp', ['--dry-run' => true])
            ->expectsOutputToContain('would delete extracted_1700000000_abcd1234')
            ->expectsOutputToContain('Would delete 5 update temp entries')
            ->assertSuccessful();

        $this->assertSame($before, $this->left());
    }

    public function test_older_than_keeps_recent_leftovers(): void
    {
        touch($this->temp.'/update_1700000000_abcd1234.zip', time() - 2 * 86400);

        $this->artisan('update:clean-temp', ['--older-than' => 24])
            ->expectsOutputToContain('Deleted 1 update temp entry')
            ->assertSuccessful();

        $this->assertNotContains('update_1700000000_abcd1234.zip', $this->left());
        $this->assertContains('extracted_1700000000_abcd1234', $this->left());
    }

    public function test_it_rejects_a_negative_age(): void
    {
        $this->artisan('update:clean-temp', ['--older-than' => '-1'])->assertFailed();

        $this->assertContains('update_1700000000_abcd1234.zip', $this->left());
    }

    public function test_it_never_removes_the_folder_of_a_restore_that_may_still_be_running(): void
    {
        // A restore unpacks the backup into restore_{time}_{random}. Even
        // when the update lock has expired under a long restore, a sweep
        // without an age limit must leave a recent one alone.
        $running = 'restore_'.time().'_0a1b2c3d';
        File::ensureDirectoryExists($this->temp.'/'.$running.'/app');
        $finished = 'restore_'.(time() - FileUpdateService::RESTORE_RUNNING_SECONDS - 60).'_0a1b2c3d';
        File::ensureDirectoryExists($this->temp.'/'.$finished);

        $this->artisan('update:clean-temp')->assertSuccessful();

        $this->assertContains($running, $this->left());
        $this->assertTrue(File::isDirectory($this->temp.'/'.$running.'/app'));
        $this->assertNotContains($finished, $this->left());
        $this->assertNotContains('restore_1700000000', $this->left());
    }

    public function test_it_refuses_while_an_update_is_running(): void
    {
        $lock = Cache::lock(UpdateService::LOCK, 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('update:clean-temp')
                ->expectsOutputToContain('An update is in progress')
                ->assertFailed();
        } finally {
            $lock->release();
        }

        $this->assertContains('update_1700000000_abcd1234.zip', $this->left());
    }

    public function test_a_backup_sweeps_stale_update_leftovers_first(): void
    {
        $old = time() - UpdateService::STALE_TEMP_SECONDS - 60;
        touch($this->temp.'/update_1700000000_abcd1234.zip', $old);
        touch($this->temp.'/extracted_1700000000_abcd1234', $old);

        $backups = Mockery::mock(BackupService::class);
        $backups->shouldReceive('createBackup')->once()->andReturnUsing(function () {
            // Already gone by the time the archive is written.
            $this->assertNotContains('update_1700000000_abcd1234.zip', $this->left());

            return '/backups/backup.zip';
        });
        $backups->shouldReceive('lastDatabaseBackupMethod')->andReturn('php-dump');
        $this->app->instance(BackupService::class, $backups);

        $this->artisan('app:update', ['--backup' => true])->assertSuccessful();

        $left = $this->left();
        $this->assertNotContains('extracted_1700000000_abcd1234', $left);
        // Recent leftovers wait for the command or a later sweep.
        $this->assertContains('signature_0123456789ab.sig', $left);
        $this->assertContains('notes.txt', $left);
    }
}
