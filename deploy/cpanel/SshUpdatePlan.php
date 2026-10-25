<?php

declare(strict_types=1);

namespace Inventoros\Deploy;

use InvalidArgumentException;
use RuntimeException;

final class SshUpdatePlan
{
    public function __construct(private readonly array $settings)
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.@-]*\z/', $settings['ssh_host'] ?? '')) {
            throw new InvalidArgumentException('ssh_host must be an SSH config alias or user@hostname.');
        }
        foreach (['app_dir', 'php_binary'] as $key) {
            $value = $settings[$key] ?? '';
            if (! is_string($value) || ! str_starts_with($value, '/') || $value === '/' || preg_match('/[\x00-\x1f\x7f]/', $value) || in_array('..', explode('/', $value), true)) {
                throw new InvalidArgumentException("{$key} must be a non-root absolute server path without traversal or control characters.");
            }
        }
        $port = $settings['ssh_port'] ?? 22;
        if (! is_int($port) || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('ssh_port must be an integer between 1 and 65535.');
        }
    }

    public function command(string $mode): array
    {
        if (! in_array($mode, ['check', 'apply'], true)) {
            throw new InvalidArgumentException('Choose check or apply.');
        }
        $remote = 'cd -- '.self::quote($this->settings['app_dir']).' && '.self::quote($this->settings['php_binary']).' artisan app:update';
        if ($mode === 'check') {
            $remote .= ' --check --no-interaction';
        }

        return ['ssh', '-p', (string) ($this->settings['ssh_port'] ?? 22), '-o', 'StrictHostKeyChecking=yes', '-o', 'BatchMode=yes',
            $mode === 'apply' ? '-t' : '-T', $this->settings['ssh_host'], $remote];
    }

    /** $run receives argument arrays; no local shell parses paths or settings. */
    public static function assertMain(callable $run): void
    {
        if (trim($run(['git', 'branch', '--show-current'])) !== 'main') {
            throw new RuntimeException('Apply is allowed only from main.');
        }
        if (trim($run(['git', 'status', '--porcelain'])) !== '') {
            throw new RuntimeException('Commit or discard local changes before applying an update.');
        }
        $run(['git', 'fetch', '--no-tags', 'origin', 'main']);
        if (trim($run(['git', 'rev-parse', 'HEAD'])) !== trim($run(['git', 'rev-parse', 'refs/remotes/origin/main']))) {
            throw new RuntimeException('Local main must match the fetched origin/main before applying an update.');
        }
    }

    private static function quote(string $value): string
    {
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }
}
