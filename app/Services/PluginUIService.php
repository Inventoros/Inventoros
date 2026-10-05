<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Service for plugins to register custom UI elements (pages, menus, etc).
 *
 * Provides a centralized registry for menu items, custom pages,
 * dashboard widgets, and page components that plugins can add to.
 */
final class PluginUIService
{
    public const DEFAULT_POSITION = 100;
    /**
     * @var array<int, array> Registered menu items
     */
    protected array $menuItems = [];

    /**
     * @var array<string, array> Registered custom pages keyed by route name
     */
    protected array $customPages = [];

    /**
     * @var array<int, array> Registered dashboard widgets
     */
    protected array $dashboardWidgets = [];

    /**
     * @var array<string, array<string, array>> Page components keyed by page then slot
     */
    protected array $pageComponents = [];

    /**
     * The plugin whose main file is running (see whileLoading()), so what it
     * registers can be attributed to it.
     */
    protected ?string $loadingPlugin = null;

    /**
     * Run a plugin's registrations, attributing the pages, menu items and
     * widgets they add to $slug.
     *
     * @template T
     *
     * @param  callable(): T  $register
     * @return T
     */
    public function whileLoading(string $slug, callable $register): mixed
    {
        $previous = $this->loadingPlugin;
        $this->loadingPlugin = $slug;

        try {
            return $register();
        } finally {
            $this->loadingPlugin = $previous;
        }
    }

    /**
     * A translation key a plugin may label its UI with: a string under
     * "plugins.{slug}." (its own slug when known, any plugin namespace
     * otherwise). Anything else is dropped, so a plugin cannot borrow or
     * spoof a core string; the browser then shows the plain label.
     */
    protected function labelKey(mixed $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $prefix = $this->loadingPlugin !== null ? 'plugins.'.$this->loadingPlugin.'.' : 'plugins.';

        if (! is_string($key) || ! str_starts_with($key, $prefix) || ! preg_match('/^plugins\.[a-z0-9][a-z0-9-]*\.[A-Za-z0-9_.-]+$/', $key)) {
            Log::warning('Plugin UI translation key ignored: it must be a string under '.$prefix, [
                'plugin' => $this->loadingPlugin,
                'key' => is_scalar($key) ? (string) $key : get_debug_type($key),
            ]);

            return null;
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function normaliseSubmenuItem(array $item): array
    {
        $item = array_merge([
            'label' => 'Submenu Item',
            'label_key' => null,
            'route' => null,
            'url' => null,
            'icon' => null,
            'permission' => null,
            'active_routes' => [],
        ], $item);
        $item['label_key'] = $this->labelKey($item['label_key']);

        return $item;
    }

    /**
     * Register a custom menu item.
     *
     * `label_key` names a translation under plugins.{slug}. that the browser
     * shows instead of `label` once the plugin's bundle has added it.
     *
     * @param array $item Menu item configuration (label, label_key, route, url, icon, permission, position, parent, badge, active_routes, submenu)
     * @return void
     */
    public function addMenuItem(array $item): void
    {
        $defaults = [
            'label' => 'Custom Item',
            'label_key' => null,
            'route' => null,
            'url' => null,
            'icon' => null,
            'permission' => null,
            'position' => 100,
            'parent' => null,
            'badge' => null,
            'active_routes' => [],
            'submenu' => [], // Array of submenu items
        ];

        $item = array_merge($defaults, $item);
        $item['label_key'] = $this->labelKey($item['label_key']);
        $item['submenu'] = array_map(
            fn ($sub) => is_array($sub) ? $this->normaliseSubmenuItem($sub) : $sub,
            is_array($item['submenu']) ? $item['submenu'] : [],
        );

        $this->menuItems[] = $item;
    }

    /**
     * Register multiple menu items at once.
     *
     * @param array $items Array of menu item configurations
     * @return void
     */
    public function addMenuItems(array $items): void
    {
        foreach ($items as $item) {
            $this->addMenuItem($item);
        }
    }

    /**
     * Add a submenu item to an existing menu item.
     *
     * @param string $parentLabel The label of the parent menu item
     * @param array $submenuItem Submenu item configuration
     * @return void
     */
    public function addSubmenuItem(string $parentLabel, array $submenuItem): void
    {
        $submenuItem = $this->normaliseSubmenuItem($submenuItem);

        // Find the parent menu item and add the submenu item
        foreach ($this->menuItems as &$menuItem) {
            if ($menuItem['label'] === $parentLabel) {
                if (!isset($menuItem['submenu'])) {
                    $menuItem['submenu'] = [];
                }
                $menuItem['submenu'][] = $submenuItem;
                break;
            }
        }
    }

    /**
     * Get all registered menu items.
     *
     * @return array<int, array> Menu items sorted by position
     */
    public function getMenuItems(): array
    {
        // Sort by position
        usort($this->menuItems, fn($a, $b) => $a['position'] <=> $b['position']);
        return $this->menuItems;
    }

    /**
     * Register a custom page route.
     *
     * The route itself is added by routes/web.php (App\Support\PluginPageRoutes)
     * and served by App\Http\Controllers\PluginPageController.
     *
     * @param string $route Route name
     * @param string $component Inertia component name
     * @param array $options uri, permission, props (array or callable($request, $user)), title, middleware
     * @return void
     */
    public function registerPage(string $route, string $component, array $options = []): void
    {
        $defaults = [
            'uri' => null,
            'middleware' => ['auth'],
            'permission' => null,
            'title' => 'Custom Page',
            'props' => [],
        ];

        $this->customPages[$route] = array_merge($defaults, [
            'route' => $route,
            'component' => $component,
        ], $options, [
            // The plugin that registered it, for the route conflict check at
            // activation (null when registered outside a plugin's main file).
            'owner' => $this->loadingPlugin,
        ]);
    }

    /**
     * Get all registered custom pages.
     *
     * @return array<string, array> Custom pages keyed by route name
     */
    public function getCustomPages(): array
    {
        return $this->customPages;
    }

    /**
     * Register a dashboard widget.
     *
     * `title_key` names a translation under plugins.{slug}. shown instead of
     * `title` once the plugin's bundle has added it.
     *
     * @param array $widget Widget configuration (id, title, title_key, component, data, position, width, permission)
     * @return void
     */
    public function addDashboardWidget(array $widget): void
    {
        $defaults = [
            'id' => uniqid('widget_'),
            'title' => 'Custom Widget',
            'title_key' => null,
            'component' => null,
            'data' => [],
            'position' => 100,
            'width' => 'full', // 'full', 'half', 'third', 'quarter'
            'permission' => null,
        ];

        $widget = array_merge($defaults, $widget);
        $widget['title_key'] = $this->labelKey($widget['title_key']);

        $this->dashboardWidgets[] = $widget;
    }

    /**
     * Get all registered dashboard widgets.
     *
     * @return array<int, array> Widgets sorted by position
     */
    public function getDashboardWidgets(): array
    {
        // Sort by position
        usort($this->dashboardWidgets, fn($a, $b) => $a['position'] <=> $b['position']);
        return $this->dashboardWidgets;
    }

    /**
     * Register a component to be injected into an existing page.
     *
     * @param string $page Page identifier (e.g., 'product.show', 'dashboard')
     * @param string $slot Slot name (e.g., 'sidebar', 'header', 'footer', 'tabs')
     * @param array $component Component configuration (component, data, position, permission)
     * @return void
     */
    public function addPageComponent(string $page, string $slot, array $component): void
    {
        $defaults = [
            'component' => null,
            'data' => [],
            'position' => 100,
            'permission' => null,
        ];

        if (!isset($this->pageComponents[$page])) {
            $this->pageComponents[$page] = [];
        }

        if (!isset($this->pageComponents[$page][$slot])) {
            $this->pageComponents[$page][$slot] = [];
        }

        $this->pageComponents[$page][$slot][] = array_merge($defaults, $component);
    }

    /**
     * Get components registered for a specific page and slot.
     *
     * @param string $page Page identifier
     * @param string $slot Slot name
     * @return array<int, array> Components sorted by position
     */
    public function getPageComponents(string $page, string $slot): array
    {
        if (!isset($this->pageComponents[$page][$slot])) {
            return [];
        }

        $components = $this->pageComponents[$page][$slot];

        // Sort by position
        usort($components, fn($a, $b) => $a['position'] <=> $b['position']);

        return $components;
    }

    /**
     * Get all components for a specific page.
     *
     * @param string $page Page identifier
     * @return array<string, array<int, array>> Components keyed by slot name
     */
    public function getAllPageComponents(string $page): array
    {
        return $this->pageComponents[$page] ?? [];
    }

    /**
     * Whether a user may see a plugin UI entry.
     *
     * No permission means everyone who can see the host page. A string is a
     * single permission; a list means any one of them. With a permission set
     * and no user, the entry is hidden.
     *
     * @param  string|array<int, string>|null  $permission
     */
    public static function allows(?User $user, string|array|null $permission): bool
    {
        if ($permission === null || $permission === '' || $permission === []) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return is_array($permission)
            ? $user->hasAnyPermission(array_values($permission))
            : $user->hasPermission($permission);
    }

    /**
     * Menu items the user may see, with submenu entries filtered the same way.
     *
     * @return array<int, array>
     */
    public function getVisibleMenuItems(?User $user): array
    {
        $items = [];

        foreach ($this->getMenuItems() as $item) {
            if (! self::allows($user, $item['permission'] ?? null)) {
                continue;
            }

            $item['submenu'] = array_values(array_filter(
                $item['submenu'] ?? [],
                fn (array $sub) => self::allows($user, $sub['permission'] ?? null)
            ));

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Components for a page slot that the user may see, with callable `data`
     * resolved for them. Components they may not see are left out entirely,
     * and their data is never computed.
     *
     * @return array<int, array>
     */
    public function getVisiblePageComponents(?User $user, string $page, string $slot): array
    {
        $visible = [];

        foreach ($this->getPageComponents($page, $slot) as $component) {
            if (! self::allows($user, $component['permission'] ?? null)) {
                continue;
            }

            $component['data'] = $this->resolveData($component['data'] ?? [], $user, "{$page}:{$slot}");
            unset($component['permission']);

            $visible[] = $component;
        }

        return $visible;
    }

    /**
     * Dashboard widgets the user may see, shaped for the dashboard page.
     *
     * Like the built-in dashboard figures, a widget the user may not see is
     * absent rather than empty, and its data is never computed. Widgets
     * without a component are skipped.
     *
     * @return array<int, array{id: string, title: string, title_key: string|null, plugin: string|null, component: string, data: array, width: string, position: int}>
     */
    public function getVisibleDashboardWidgets(?User $user): array
    {
        $widgets = [];

        foreach ($this->getDashboardWidgets() as $widget) {
            if (! is_string($widget['component'] ?? null) || $widget['component'] === '') {
                Log::warning('Plugin dashboard widget has no component; skipped', ['id' => $widget['id'] ?? null]);

                continue;
            }

            if (! self::allows($user, $widget['permission'] ?? null)) {
                continue;
            }

            $widgets[] = [
                'id' => (string) $widget['id'],
                'title' => (string) $widget['title'],
                'title_key' => $widget['title_key'] ?? null,
                'plugin' => $widget['plugin'] ?? null,
                'component' => $widget['component'],
                'data' => $this->resolveData($widget['data'] ?? [], $user, 'dashboard widget '.$widget['id']),
                'width' => in_array($widget['width'], ['full', 'half', 'third', 'quarter'], true) ? $widget['width'] : 'full',
                'position' => (int) $widget['position'],
            ];
        }

        return $widgets;
    }

    /**
     * The page registered for a route name, if any.
     *
     * @return array<string, mixed>|null
     */
    public function getCustomPage(string $route): ?array
    {
        return $this->customPages[$route] ?? null;
    }

    /**
     * @param  mixed  $data  An array, or a callable that receives the user and returns one.
     * @return array<mixed>
     */
    private function resolveData(mixed $data, ?User $user, string $context): array
    {
        if ($data instanceof \Closure || (is_callable($data) && ! is_string($data))) {
            $data = $data($user);
        }

        if (! is_array($data)) {
            Log::warning('Plugin UI data must be an array; ignored', ['context' => $context]);

            return [];
        }

        return $data;
    }

    /**
     * Clear all registered UI elements.
     *
     * Useful for testing or resetting the UI state.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->menuItems = [];
        $this->customPages = [];
        $this->dashboardWidgets = [];
        $this->pageComponents = [];
    }
}
