<?php

declare(strict_types=1);

// A convenience wrapper for the existing signed-release updater. No server
// connection is made unless --check or --apply is explicitly supplied.
require __DIR__.'/SshUpdatePlan.php';

use Inventoros\Deploy\SshUpdatePlan;

if (in_array('--help', $argv, true)) {
    echo "php deploy/cpanel/ssh-update.php [--settings=/path/to/settings.json] [--check|--apply]\n";
    echo "Default: print a plan without connecting. Apply requires clean, pushed main and keeps the updater's interactive confirmation.\n";
    exit(0);
}

try {
    $mode = 'plan';
    $file = __DIR__.'/ssh-settings.local.json';
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--settings=')) {
            $file = substr($argument, strlen('--settings='));
        } elseif (in_array($argument, ['--check', '--apply'], true) && $mode === 'plan') {
            $mode = substr($argument, 2);
        } else {
            throw new InvalidArgumentException('Unknown or conflicting argument: '.$argument);
        }
    }
    $json = @file_get_contents($file);
    if ($json === false) {
        throw new RuntimeException('Copy ssh-settings.example.json to ssh-settings.local.json and configure the server first.');
    }
    $plan = new SshUpdatePlan(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    if ($mode === 'plan') {
        echo "No connection made. Check command arguments:\n".json_encode($plan->command('check'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        echo "Use --check to read the server's update status, or --apply from clean, pushed main to run the interactive signed-release updater.\n";
        exit(0);
    }
    $root = dirname(__DIR__, 2);
    if ($mode === 'apply') {
        if (! function_exists('stream_isatty') || ! stream_isatty(STDIN)) {
            throw new RuntimeException('Apply requires an interactive terminal so the server update can be reviewed and confirmed.');
        }
        SshUpdatePlan::assertMain(static function (array $command) use ($root): string {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, $root);
            if (! is_resource($process)) throw new RuntimeException('Could not start Git.');
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            if (proc_close($process) !== 0) throw new RuntimeException('Git verification failed; no server connection made.');
            return $output;
        });
    }
    $process = proc_open($plan->command($mode), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (! is_resource($process)) throw new RuntimeException('Could not start SSH.');
    exit(proc_close($process));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
