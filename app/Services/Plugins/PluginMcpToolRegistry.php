<?php

declare(strict_types=1);

namespace App\Services\Plugins;

use App\Mcp\Plugins\PluginTool;
use App\Mcp\Servers\InventorosServer;
use InvalidArgumentException;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tools added by active plugins (register_mcp_tool()).
 *
 * A plugin tool is a normal laravel/mcp Tool class from the plugin. Its name
 * must start with the plugin slug in snake case ("cycle-counts" registers
 * "cycle_counts_..."), so it can never shadow a core tool or another
 * plugin's, and it must name the permission(s) that unlock it. Core wraps it
 * (PluginTool) and checks those permissions against the user and the token's
 * abilities before listing or calling it.
 */
final class PluginMcpToolRegistry
{
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    // Lowercase snake_case. A leading digit is allowed (MCP tool names may
    // start with one) because slugs may: plugin "3pl" registers "3pl_...".
    // The slug prefix check below is what namespaces the name.
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9]*(_[a-z0-9]+)*$/';

    /** @var array<string, PluginTool> keyed by tool name */
    private array $tools = [];

    /**
     * @param  Tool|class-string<Tool>  $tool
     * @param  string|array<int, string>  $permission  Any of these grants access
     *
     * @throws InvalidArgumentException
     */
    public function register(string $slug, Tool|string $tool, string|array $permission): void
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException("\"{$slug}\" is not a plugin slug.");
        }

        if (is_string($tool)) {
            if (! is_subclass_of($tool, Tool::class)) {
                throw new InvalidArgumentException("MCP tool {$tool} must extend ".Tool::class.'.');
            }

            $tool = app($tool);
        }

        $name = $tool->name();
        $prefix = self::prefix($slug);

        if (preg_match(self::NAME_PATTERN, $name) !== 1 || ! str_starts_with($name, $prefix)) {
            throw new InvalidArgumentException("MCP tool \"{$name}\" of plugin {$slug} must be named in snake_case starting with \"{$prefix}\".");
        }

        if (in_array($name, self::coreToolNames(), true)) {
            throw new InvalidArgumentException("MCP tool \"{$name}\" would replace a core tool.");
        }

        $permissions = array_values(array_filter((array) $permission, fn ($p) => is_string($p) && $p !== ''));

        if ($permissions === []) {
            throw new InvalidArgumentException("MCP tool \"{$name}\" must name the permission (or permissions, any of) that unlock it.");
        }

        $this->tools[$name] = new PluginTool($tool, $slug, $permissions);
    }

    /**
     * @return array<int, PluginTool>
     */
    public function tools(): array
    {
        return array_values($this->tools);
    }

    public function clear(): void
    {
        $this->tools = [];
    }

    public static function prefix(string $slug): string
    {
        return str_replace('-', '_', $slug).'_';
    }

    /**
     * @return array<int, string>
     */
    private static function coreToolNames(): array
    {
        return array_map(fn (string $class) => app($class)->name(), InventorosServer::coreTools());
    }
}
