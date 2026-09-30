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

/**
 * After an update swaps vendor/ and app/, the running PHP process still holds
 * the old classes (and config:cache would re-point the facades at a freshly
 * booted mixed application). Cache and migration commands therefore run in
 * a new `php artisan` process against the new files.
 */
final class UpdateArtisanSubprocessTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = storage_path('app/testing/artisan_'.uniqid());
        File::ensureDirectoryExists($this->base);

        // A stand-in artisan: records how it was called, fails on "migrate".
        File::put($this->base.'/artisan', <<<'PHP'
<?php
file_put_contents(__DIR__.'/calls.log', getcwd().'|'.implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
if (($argv[1] ?? '') === 'migrate') {
    fwrite(STDERR, "SQLSTATE[42S01]: table already exists\n");
    exit(3);
}
echo "done\n";
PHP);

        config(['update.run_artisan_in_subprocess' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/testing'));
        Mockery::close();

        parent::tearDown();
    }

    private function service(): UpdateService
    {
        return new class(Mockery::mock(GitHubReleaseService::class), Mockery::mock(BackupService::class), new FileUpdateService($this->base, $this->base)) extends UpdateService
        {
            public function run(string $command, array $parameters = []): void
            {
                $this->artisan($command, $parameters);
            }
        };
    }

    public function test_commands_run_in_a_new_php_process_in_the_app_directory(): void
    {
        Artisan::shouldReceive('call')->never();

        $this->service()->run('optimize:clear');
        $this->service()->run('optimize');

        $calls = file($this->base.'/calls.log', FILE_IGNORE_NEW_LINES);
        $this->assertSame([
            realpath($this->base).'|optimize:clear --no-interaction --no-ansi',
            realpath($this->base).'|optimize --no-interaction --no-ansi',
        ], array_map(
            fn (string $line) => str_replace('/', DIRECTORY_SEPARATOR, $line),
            $calls
        ));
    }

    public function test_options_are_passed_through(): void
    {
        $this->service()->run('down', ['--retry' => 60]);

        $this->assertStringContainsString('|down --retry=60', File::get($this->base.'/calls.log'));
    }

    public function test_a_failing_command_throws_with_its_output(): void
    {
        try {
            $this->service()->run('migrate', ['--force' => true]);
            $this->fail('A failed migration must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('migrate', $e->getMessage());
            $this->assertStringContainsString('table already exists', $e->getMessage());
        }

        $this->assertStringContainsString('|migrate --force', File::get($this->base.'/calls.log'));
    }

    public function test_in_process_calls_are_used_when_subprocesses_are_disabled(): void
    {
        config(['update.run_artisan_in_subprocess' => false]);
        Artisan::shouldReceive('call')->once()->with('optimize', [])->andReturn(0);

        $this->service()->run('optimize');

        $this->assertFileDoesNotExist($this->base.'/calls.log');
    }
}
