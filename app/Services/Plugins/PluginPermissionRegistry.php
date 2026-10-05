<?php

declare(strict_types=1);

namespace App\Services\Plugins;

use InvalidArgumentException;

/**
 * Permissions that active plugins register with register_permission().
 *
 * A plugin permission is named "{plugin-slug}.{ability}", for example
 * "cycle-counts.approve". Core permission names never contain a dot, so a
 * plugin can never shadow or redefine one. Registrations live for one
 * application, like every other plugin registration: a deactivated plugin's
 * permissions are simply not registered, so they are not listed in the role
 * editor and cannot be put on new API tokens (grants already stored on roles
 * stay inert until the plugin returns, and are removed when it is deleted).
 */
final class PluginPermissionRegistry
{
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]*\.[a-z0-9][a-z0-9_]*$/';

    public const DEFAULT_CATEGORY = 'Plugins';

    /**
     * @var array<string, array{value: string, label: string, description: string, category: string}>
     */
    private array $permissions = [];

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Register (or re-register, replacing the label) a plugin permission.
     *
     * @throws InvalidArgumentException When the name is not "{slug}.{ability}" or the label is empty.
     */
    public function register(string $name, string $label, string $description = '', ?string $category = null): void
    {
        if (! self::isValidName($name)) {
            throw new InvalidArgumentException(
                "Plugin permission \"{$name}\" must be named \"{plugin-slug}.{ability}\": lowercase letters, digits and hyphens, a dot, then lowercase letters, digits and underscores."
            );
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException("Plugin permission \"{$name}\" needs a label.");
        }

        $category = $category !== null && trim($category) !== '' ? trim($category) : self::DEFAULT_CATEGORY;

        $this->permissions[$name] = [
            'value' => $name,
            'label' => $label,
            'description' => $description,
            'category' => $category,
        ];
    }

    public function has(string $name): bool
    {
        return isset($this->permissions[$name]);
    }

    /**
     * @return array{value: string, label: string, description: string, category: string}|null
     */
    public function get(string $name): ?array
    {
        return $this->permissions[$name] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->permissions);
    }

    /**
     * Registered permissions grouped by category, shaped like Permission::grouped().
     *
     * @return array<string, array<int, array{value: string, label: string, description: string}>>
     */
    public function grouped(): array
    {
        $grouped = [];

        foreach ($this->permissions as $permission) {
            $grouped[$permission['category']][] = [
                'value' => $permission['value'],
                'label' => $permission['label'],
                'description' => $permission['description'],
            ];
        }

        return $grouped;
    }

    /**
     * Remove one plugin's permissions (the "{slug}." prefix) from a list of names.
     *
     * @param  array<int, mixed>  $names
     * @return array<int, mixed> $names without the plugin's permissions, reindexed
     */
    public static function withoutPluginPermissions(array $names, string $slug): array
    {
        $prefix = $slug.'.';

        return array_values(array_filter(
            $names,
            fn ($name) => ! (is_string($name) && str_starts_with($name, $prefix)),
        ));
    }

    public function clear(): void
    {
        $this->permissions = [];
    }
}
