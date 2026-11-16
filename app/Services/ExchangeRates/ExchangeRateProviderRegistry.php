<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

use App\Services\ExchangeRates\Providers\FrankfurterProvider;
use App\Services\ExchangeRates\Providers\ManualProvider;
use InvalidArgumentException;

/**
 * The exchange rate providers available in this process: the core ones and
 * those registered by active plugins (register_exchange_rate_provider()).
 *
 * Like the other plugin registries it is filled while plugins load, so a
 * deactivated plugin's provider is simply absent on the next request or job.
 * Organizations and purchase orders that name a provider keep the id; core
 * treats an id that is not registered as "provider unavailable" and never
 * replaces it with another provider on its own.
 */
final class ExchangeRateProviderRegistry
{
    /** Core provider ids, which a plugin can never take. */
    public const CORE = [FrankfurterProvider::ID, ManualProvider::ID];

    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}\z/';

    private const ID_PATTERN = '/^[a-z0-9][a-z0-9.-]{0,63}\z/';

    /** @var array<string, ExchangeRateProvider> */
    private array $providers = [];

    /** @var array<string, string> provider id => plugin slug */
    private array $plugins = [];

    public function __construct()
    {
        $this->providers[FrankfurterProvider::ID] = new FrankfurterProvider;
        $this->providers[ManualProvider::ID] = new ManualProvider;
    }

    /**
     * Register a plugin's provider.
     *
     * @param  ExchangeRateProvider|class-string<ExchangeRateProvider>  $provider
     *
     * @throws InvalidArgumentException when the slug, class or id is not acceptable
     */
    public function register(string $slug, ExchangeRateProvider|string $provider): void
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException("\"{$slug}\" is not a plugin slug.");
        }

        if (is_string($provider)) {
            if (! is_subclass_of($provider, ExchangeRateProvider::class)) {
                throw new InvalidArgumentException("Exchange rate provider {$provider} must implement ".ExchangeRateProvider::class.'.');
            }

            $provider = app($provider);
        }

        $id = $provider->id();

        if (preg_match(self::ID_PATTERN, $id) !== 1 || ($id !== $slug && ! str_starts_with($id, $slug.'.'))) {
            throw new InvalidArgumentException("Exchange rate provider \"{$id}\" of plugin {$slug} must be named \"{$slug}\" or start with \"{$slug}.\" (lowercase letters, digits, dots and dashes).");
        }

        if (in_array($id, self::CORE, true)) {
            throw new InvalidArgumentException("Exchange rate provider \"{$id}\" would replace a core provider.");
        }

        if (trim($provider->name()) === '') {
            throw new InvalidArgumentException("Exchange rate provider \"{$id}\" needs a name.");
        }

        $this->providers[$id] = $provider;
        $this->plugins[$id] = $slug;
    }

    public function has(?string $id): bool
    {
        return $id !== null && isset($this->providers[$id]);
    }

    public function get(?string $id): ?ExchangeRateProvider
    {
        return $id === null ? null : ($this->providers[$id] ?? null);
    }

    /**
     * @return array<string, ExchangeRateProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * The plugin that registered a provider, or null for a core one.
     */
    public function pluginFor(string $id): ?string
    {
        return $this->plugins[$id] ?? null;
    }

    /**
     * A display name for an id, registered or not (a gone plugin's provider
     * is still named on the purchase orders that used it).
     */
    public function nameFor(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $this->get($id)?->name() ?? $id;
    }

    /**
     * Forget the plugin providers (tests, and long-running workers that
     * reload plugins).
     */
    public function clear(): void
    {
        foreach (array_keys($this->plugins) as $id) {
            unset($this->providers[$id]);
        }

        $this->plugins = [];
    }
}
