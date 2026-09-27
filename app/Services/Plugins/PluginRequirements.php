<?php

declare(strict_types=1);

namespace App\Services\Plugins;

use App\Support\AppVersion;
use RuntimeException;

/**
 * Enforces a plugin manifest's `requires` (minimum Inventoros version) and
 * `requires_php` (minimum PHP version).
 */
final class PluginRequirements
{
    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws RuntimeException When a requirement is not met or cannot be read.
     */
    public static function assertMet(array $manifest, string $pluginName): void
    {
        self::assertMinimum(
            $manifest['requires'] ?? null,
            AppVersion::current(),
            'requires',
            fn (string $min, string $running) => "{$pluginName} requires Inventoros {$min} or newer; this installation runs {$running}.",
            $pluginName,
        );

        self::assertMinimum(
            $manifest['requires_php'] ?? null,
            PHP_VERSION,
            'requires_php',
            fn (string $min, string $running) => "{$pluginName} requires PHP {$min} or newer; this server runs {$running}.",
            $pluginName,
        );
    }

    /**
     * @param  callable(string, string): string  $message
     */
    private static function assertMinimum(mixed $required, string $running, string $field, callable $message, string $pluginName): void
    {
        if ($required === null || $required === '') {
            return;
        }

        $minimum = is_string($required) ? AppVersion::normalise($required) : null;

        if ($minimum === null) {
            throw new RuntimeException(
                "{$pluginName} has an invalid \"{$field}\" in plugin.json: it is not a valid version number."
            );
        }

        $current = AppVersion::normalise($running) ?? $running;

        if (version_compare($current, $minimum, '<')) {
            throw new RuntimeException($message($minimum, $current));
        }
    }
}
