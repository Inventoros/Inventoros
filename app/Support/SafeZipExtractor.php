<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Validates ZIP archives before extraction so a malicious archive can't
 * escape the destination directory via path traversal (zip-slip) or chew
 * up the filesystem via entry counts / uncompressed-size bombs.
 *
 * Used by plugin uploads, in-app updates, and backup restores — the three
 * surfaces in this codebase that accept a ZIP from a potentially-attacker-
 * controlled source.
 *
 * The validator does NOT enforce any naming convention on entries beyond
 * safety — callers that need a single-root-folder layout (PluginService)
 * apply that check on top.
 */
final class SafeZipExtractor
{
    /**
     * Walk every entry in $zip and reject paths that would escape
     * $destinationDir, archives that exceed the configured entry-count
     * cap, or archives whose uncompressed size exceeds the byte cap.
     *
     * @param array{max_entries?: int, max_bytes?: int} $opts
     * @throws RuntimeException When the archive is unsafe.
     */
    public static function validate(ZipArchive $zip, string $destinationDir, array $opts = []): void
    {
        $maxEntries = $opts['max_entries'] ?? 2000;
        $maxBytes = $opts['max_bytes'] ?? 50 * 1024 * 1024;

        if ($zip->numFiles > $maxEntries) {
            throw new RuntimeException("ZIP exceeds entry count limit ({$zip->numFiles} > {$maxEntries})");
        }

        $destinationReal = realpath($destinationDir);
        if (!$destinationReal) {
            File::makeDirectory($destinationDir, 0755, true, true);
            $destinationReal = realpath($destinationDir);
        }
        if (!$destinationReal) {
            throw new RuntimeException("Destination directory does not exist and could not be created: {$destinationDir}");
        }

        $totalBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new RuntimeException("Could not stat entry #{$i}");
            }

            $name = $stat['name'];

            if ($name === '' || $name[0] === '/' || $name[0] === '\\') {
                throw new RuntimeException("ZIP contains absolute path entry: {$name}");
            }
            if (str_contains($name, '..') || str_contains($name, '\\')) {
                throw new RuntimeException("ZIP contains unsafe path entry: {$name}");
            }

            $totalBytes += (int) ($stat['size'] ?? 0);
            if ($totalBytes > $maxBytes) {
                throw new RuntimeException('ZIP exceeds uncompressed-size limit');
            }

            // Canonicalize the would-be target's parent and ensure it lands
            // inside the destination root. realpath() returns false for
            // not-yet-created paths so we resolve the existing parent and
            // verify the prefix.
            $target = $destinationReal . DIRECTORY_SEPARATOR . $name;
            $parent = dirname($target);
            $parentReal = realpath($parent) ?: $destinationReal;
            if ($parentReal !== $destinationReal
                && !str_starts_with($parentReal . DIRECTORY_SEPARATOR, $destinationReal . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("ZIP entry would escape destination directory: {$name}");
            }
        }
    }
}
