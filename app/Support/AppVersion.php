<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The running Inventoros version, as recorded in the VERSION file that every
 * release (and the in-app updater) writes.
 */
final class AppVersion
{
    public static function current(): string
    {
        $file = base_path('VERSION');

        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? $version : '1.0.0';
    }

    /**
     * Normalise a version string ("v1.2", "1.2.0-beta") for version_compare(),
     * or return null when it is not a version at all.
     */
    public static function normalise(string $version): ?string
    {
        $version = ltrim(trim($version), 'vV');

        return preg_match('/^\d+(\.\d+){0,2}([-+][0-9A-Za-z.-]+)?$/', $version) === 1 ? $version : null;
    }
}
