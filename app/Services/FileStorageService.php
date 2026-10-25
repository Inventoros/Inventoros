<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class FileStorageService
{
    public function uploadsDisk(string $legacyDisk = 'local'): string
    {
        return config('filesystems.uploads_disk') ?: $legacyDisk;
    }

    /** @return array<string, string> */
    public function purposes(): array
    {
        return [
            'products' => $this->uploadsDisk('public'),
            'logos' => $this->uploadsDisk(),
            'labels' => $this->uploadsDisk(),
            'imports' => config('imports.disk'),
            'exports' => config('exports.disk'),
        ];
    }

    /** Only explicitly allowlisted, non-secret configuration is sent to the UI. */
    public function disks(): array
    {
        $purposes = $this->purposes();

        return collect(array_values(array_unique($purposes)))->map(function (string $name) use ($purposes) {
            $config = config("filesystems.disks.{$name}", []);

            return [
                'name' => $name,
                'driver' => $config['driver'] ?? null,
                'bucket' => $config['bucket'] ?? null,
                'region' => $config['region'] ?? null,
                'purposes' => array_keys($purposes, $name, true),
                'last_check' => Cache::get($this->checkKey($name)),
            ];
        })->all();
    }

    /** Probe only a random object owned by this request, never existing files. */
    public function check(string $name): array
    {
        $path = '.inventoros/connection-checks/'.Str::uuid().'.txt';
        $payload = Str::random(64);
        $step = 'connect';
        $disk = null;
        $cleanupFailed = false;
        $success = false;

        try {
            $disk = Storage::disk($name);
            $step = 'write';
            if (! $disk->put($path, $payload, ['visibility' => 'private'])) {
                throw new RuntimeException('Write failed.');
            }
            $step = 'read';
            if (! hash_equals($payload, (string) $disk->get($path))) {
                throw new RuntimeException('Read did not match.');
            }
            $step = 'delete';
            if (! $disk->delete($path) || $disk->fileExists($path)) {
                throw new RuntimeException('Delete failed.');
            }
            $success = true;
        } catch (Throwable) {
            // SDK errors can contain credentials, signed URLs and response
            // bodies. Return the failed operation, never the exception text.
        } finally {
            if (! $success && $disk !== null) {
                try {
                    $cleanupFailed = ! $disk->delete($path);
                } catch (Throwable) {
                    $cleanupFailed = true;
                }
            }
        }

        $result = [
            'success' => $success,
            'failed_step' => $success ? null : $step,
            'cleanup_failed' => $cleanupFailed,
            'checked_at' => now()->toIso8601String(),
        ];
        // A change to bucket, endpoint, prefix or credentials invalidates the
        // previous confirmation. Queue workers still need restarting on edits.
        Cache::put($this->checkKey($name), $result, now()->addHour());

        return $result;
    }

    private function checkKey(string $name): string
    {
        return 'storage-check.'.hash('sha256', $name.json_encode(config("filesystems.disks.{$name}")));
    }
}
