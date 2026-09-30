<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Foundation\Application;

/**
 * The web root of a split install (cPanel: the app in ~/inventoros, the web
 * root in ~/public_html).
 *
 * The web front controller tells Laravel where it lives, but CLI commands
 * (plugin activation publishing UI bundles, `php artisan update`) only know
 * the default <app>/public. APP_PUBLIC_PATH, written by the installer and
 * by the web updater, closes that gap. It may be absolute or relative to
 * the application directory.
 */
final class PublicPath
{
    /**
     * Resolve a configured APP_PUBLIC_PATH to an existing directory.
     */
    public static function resolve(string $basePath, ?string $configured): ?string
    {
        $configured = trim((string) $configured);
        if ($configured === '') {
            return null;
        }

        $candidate = self::isAbsolute($configured)
            ? $configured
            : rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.$configured;

        $real = realpath($candidate);

        return $real !== false && is_dir($real) ? $real : null;
    }

    /**
     * Point the application at the configured web root, unless the entry
     * point already set one explicitly (the web front controller does).
     */
    public static function apply(Application $app): void
    {
        if (self::normalize($app->publicPath()) !== self::normalize($app->basePath('public'))) {
            return;
        }

        $resolved = self::resolve($app->basePath(), $app['config']->get('app.public_path'));

        if ($resolved !== null) {
            $app->usePublicPath($resolved);
        }
    }

    /**
     * The APP_PUBLIC_PATH value for a web root, relative to the application
     * directory where possible; null when the web root is the default
     * <app>/public and nothing needs recording.
     */
    public static function envValue(string $basePath, string $publicPath): ?string
    {
        $base = self::normalize(realpath($basePath) ?: $basePath);
        $public = self::normalize(realpath($publicPath) ?: $publicPath);

        if (self::same($public, $base.'/public')) {
            return null;
        }

        return self::relative($base, $public) ?? $public;
    }

    /**
     * The relative path from one absolute directory to another, or null when
     * they share no root (different Windows drives).
     */
    public static function relative(string $from, string $to): ?string
    {
        $fromParts = array_values(array_filter(explode('/', self::normalize($from)), fn ($p) => $p !== ''));
        $toParts = array_values(array_filter(explode('/', self::normalize($to)), fn ($p) => $p !== ''));

        $fromRoot = $fromParts[0] ?? '';
        $toRoot = $toParts[0] ?? '';
        if ((str_ends_with($fromRoot, ':') || str_ends_with($toRoot, ':')) && ! self::same($fromRoot, $toRoot)) {
            return null;
        }

        $common = 0;
        while (isset($fromParts[$common], $toParts[$common]) && self::same($fromParts[$common], $toParts[$common])) {
            $common++;
        }

        $up = array_fill(0, count($fromParts) - $common, '..');

        return implode('/', array_merge($up, array_slice($toParts, $common)));
    }

    public static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function same(string $a, string $b): bool
    {
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
