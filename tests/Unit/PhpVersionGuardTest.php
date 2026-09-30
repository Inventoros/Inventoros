<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Every entry point refuses an unsupported PHP with a plain message before
 * Composer's platform check (or the autoloader) can fail cryptically.
 */
final class PhpVersionGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function entryPoints(): array
    {
        return [
            'public/index.php' => ['public/index.php'],
            'cPanel public_html/index.php' => ['deploy/cpanel/index.php'],
            'artisan' => ['artisan'],
        ];
    }

    private function minimumPhp(): string
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        return ltrim((string) $composer['require']['php'], '^~>=');
    }

    #[DataProvider('entryPoints')]
    public function test_the_guard_matches_composer_json(string $file): void
    {
        $source = (string) file_get_contents(base_path($file));

        $this->assertStringContainsString(
            "version_compare(PHP_VERSION, '{$this->minimumPhp()}', '<')",
            $source
        );
    }

    #[DataProvider('entryPoints')]
    public function test_an_old_php_gets_a_plain_message_before_anything_is_loaded(string $file): void
    {
        // Pretend the minimum is far in the future and run the entry point
        // from a directory with no vendor/: the guard must stop it first.
        $dir = storage_path('app/testing/guard_'.uniqid());
        File::ensureDirectoryExists($dir.'/public');
        $script = $file === 'artisan' ? $dir.'/artisan' : $dir.'/public/index.php';

        File::put($script, str_replace(
            $this->minimumPhp(),
            '99.0.0',
            (string) file_get_contents(base_path($file))
        ));

        try {
            $process = new Process([(new PhpExecutableFinder)->find(), $script], $dir);
            $process->run();

            $output = $process->getOutput().$process->getErrorOutput();
            $this->assertSame(1, $process->getExitCode(), $output);
            $this->assertStringContainsString('Inventoros requires PHP 99.0.0 or newer', $output);
            $this->assertStringContainsString('This server runs PHP '.PHP_VERSION, $output);
            $this->assertStringNotContainsString('autoload', $output);
        } finally {
            File::deleteDirectory(storage_path('app/testing'));
        }
    }
}
