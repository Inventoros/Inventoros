<?php

declare(strict_types=1);

namespace Tests\Unit;

use Inventoros\Deploy\SshUpdatePlan;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../deploy/cpanel/SshUpdatePlan.php';

final class SshUpdatePlanTest extends TestCase
{
    private function settings(): array
    {
        return ['ssh_host' => 'server-alias', 'app_dir' => "/home/a user's/inventoros", 'php_binary' => '/opt/cpanel/php'];
    }

    public function test_server_arguments_are_kept_separate_and_remote_paths_are_quoted(): void
    {
        $command = (new SshUpdatePlan($this->settings()))->command('check');
        $this->assertSame('ssh', $command[0]);
        $this->assertSame('server-alias', $command[count($command) - 2]);
        $this->assertStringContainsString("'\"'\"'", end($command));
        $this->assertStringEndsWith('artisan app:update --check --no-interaction', end($command));
        $this->assertContains('StrictHostKeyChecking=yes', $command);
        $this->assertContains('-t', (new SshUpdatePlan($this->settings()))->command('apply'));
    }

    public function test_apply_refuses_noninteractive_execution_before_any_server_connection(): void
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([PHP_BINARY, $root.'/deploy/cpanel/ssh-update.php', '--apply', '--settings='.$root.'/deploy/cpanel/ssh-settings.example.json'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(1, proc_close($process));
        $this->assertStringContainsString('requires an interactive terminal', $error);
    }

    public function test_an_ssh_option_cannot_be_supplied_as_the_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SshUpdatePlan(array_merge($this->settings(), ['ssh_host' => '-oProxyCommand=anything']));
    }

    public function test_root_paths_and_traversal_are_rejected(): void
    {
        foreach (['/', '/home/user/../other', "/home/user\ncommand"] as $path) {
            try {
                new SshUpdatePlan(array_merge($this->settings(), ['app_dir' => $path]));
                $this->fail('Unsafe path accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_feature_branch_is_refused_before_fetching_or_connecting(): void
    {
        $commands = [];
        try {
            SshUpdatePlan::assertMain(function ($command) use (&$commands) { $commands[] = $command; return 'feature'; });
            $this->fail('Feature branch accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Apply is allowed only from main.', $e->getMessage());
            $this->assertCount(1, $commands);
        }
    }

    public function test_dirty_or_unpushed_main_is_refused_and_the_fetched_commit_is_checked(): void
    {
        foreach ([[' M file', 'a', 'a'], ['', 'a', 'b'], ['', 'a', 'a']] as [$status, $head, $remote]) {
            $accepted = false;
            try {
                SshUpdatePlan::assertMain(fn ($command) => match (implode(' ', $command)) {
                    'git branch --show-current' => 'main', 'git status --porcelain' => $status,
                    'git rev-parse HEAD' => $head, 'git rev-parse refs/remotes/origin/main' => $remote, default => '',
                });
                $accepted = true;
            } catch (\RuntimeException) {}
            $this->assertSame($status === '' && $head === $remote, $accepted);
        }
    }
}
