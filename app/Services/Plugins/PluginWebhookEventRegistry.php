<?php

declare(strict_types=1);

namespace App\Services\Plugins;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Outbound webhook events added by active plugins (register_webhook_event()).
 *
 * An event is named "{plugin-slug}.{event}" (for example
 * "cycle-counts.session_completed"). Core webhook event names begin with a
 * core resource ("product.", "order.", ...), so a plugin event can never be
 * mistaken for one. Registered events are listed in the webhook event picker
 * and accepted in webhook subscriptions; dispatch_webhook_event() sends them
 * through core's delivery (signing, retries, SSRF checks).
 */
final class PluginWebhookEventRegistry
{
    private const NAME_PATTERN = '/^([a-z0-9][a-z0-9-]{0,63})\.[a-z0-9_]+(\.[a-z0-9_]+)*$/';

    /** @var array<string, array{description: string, group: string, plugin: string}> */
    private array $events = [];

    /**
     * @throws InvalidArgumentException When the name is not "{slug}.{event}" or uses a core prefix
     */
    public function register(string $event, string $description, ?string $group = null, array $coreGroups = []): void
    {
        if (preg_match(self::NAME_PATTERN, $event, $matches) !== 1) {
            throw new InvalidArgumentException(
                "Plugin webhook event \"{$event}\" must be named \"{plugin-slug}.{event}\": the slug, a dot, then lowercase letters, digits and underscores (more dot-separated parts allowed)."
            );
        }

        $slug = $matches[1];

        if (in_array($slug, $coreGroups, true)) {
            throw new InvalidArgumentException("Plugin webhook event \"{$event}\" would use the core \"{$slug}.\" prefix.");
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException("Plugin webhook event \"{$event}\" needs a description for the event picker.");
        }

        $this->events[$event] = [
            'description' => $description,
            'group' => $group !== null && trim($group) !== '' ? $group : Str::headline($slug),
            'plugin' => $slug,
        ];
    }

    public function has(string $event): bool
    {
        return isset($this->events[$event]);
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->events);
    }

    /**
     * Picker groups: group label => [event => description].
     *
     * @return array<string, array<string, string>>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->events as $event => $definition) {
            $groups[$definition['group']][$event] = $definition['description'];
        }

        return $groups;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
