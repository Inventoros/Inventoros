<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs `php artisan <command>` in a new PHP process.
 *
 * Commands that boot a second application (config:cache, route:cache,
 * optimize) re-point the facades and the container at it, which breaks the
 * rest of a web request, and after an update the running process holds the
 * old classes. A separate process sees only the files on disk.
 */
class ArtisanProcess
{
    /**
     * Whether a PHP CLI process can be started here (proc_open allowed and a
     * PHP binary found), and update.run_artisan_in_subprocess is on.
     */
    public function available(): bool
    {
        return $this->phpBinary() !== null;
    }

    /**
     * @param  array<string, mixed>  $parameters  Options, e.g. ['--force' => true, '--retry' => 60]
     * @param  string|null  $cwd  The application directory (defaults to base_path())
     *
     * @throws RuntimeException When no process can be started or the command fails.
     */
    public function run(string $command, array $parameters = [], ?string $cwd = null): string
    {
        $php = $this->phpBinary();
        if ($php === null) {
            throw new RuntimeException('Cannot start a PHP process (proc_open is disabled or no PHP CLI binary was found).');
        }

        $arguments = [$php, 'artisan', $command];
        foreach ($parameters as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            $arguments[] = $value === true ? $name : "{$name}={$value}";
        }
        $arguments[] = '--no-interaction';
        $arguments[] = '--no-ansi';

        $process = new Process($arguments, $cwd ?? base_path(), null, null, (float) config('update.artisan_timeout', 900));
        $process->run();

        if (! $process->isSuccessful()) {
            // The reason comes last (artisan prints the exception after the
            // progress lines), so keep the tail.
            $output = trim($process->getOutput()."\n".$process->getErrorOutput());

            throw new RuntimeException(sprintf(
                'php artisan %s failed (exit %s): %s',
                $command,
                (string) $process->getExitCode(),
                mb_substr($output, -1500)
            ));
        }

        return $process->getOutput();
    }

    /**
     * The PHP CLI binary, or null when processes cannot or should not run.
     */
    protected function phpBinary(): ?string
    {
        if (! config('update.run_artisan_in_subprocess', true)) {
            return null;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (! function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return null;
        }

        $configured = (string) config('update.php_binary', '');
        if ($configured !== '') {
            return $configured;
        }

        // Under PHP-FPM, PHP_BINARY is the FPM daemon; the finder looks for
        // the CLI binary next to it (e.g. /opt/cpanel/ea-php84/root/usr/bin/php).
        $found = (new PhpExecutableFinder)->find(false);

        return is_string($found) && $found !== '' ? $found : null;
    }
}
