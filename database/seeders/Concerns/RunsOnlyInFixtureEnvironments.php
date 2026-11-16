<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

/**
 * For seeders that create accounts with passwords published in this
 * repository (end-to-end, screenshot and demo fixtures).
 *
 * They run only in the environments where those accounts are disposable:
 * local development (CI copies .env.example, which sets APP_ENV=local) and
 * the test suite. Every other environment is refused, staging included, so
 * a known login can never be created on a live install by accident.
 */
trait RunsOnlyInFixtureEnvironments
{
    /**
     * @throws \RuntimeException Outside the local and testing environments.
     */
    protected function ensureFixtureEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException(sprintf(
                '%s creates accounts with known passwords and only runs in the local or testing environment (current: %s).',
                class_basename(static::class),
                app()->environment(),
            ));
        }
    }
}
