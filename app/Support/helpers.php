<?php

declare(strict_types=1);

use App\Facades\Hook;

if (!function_exists('add_action')) {
    /**
     * Add an action hook
     *
     * @param string $tag
     * @param callable $callback
     * @param int $priority
     * @return void
     */
    function add_action(string $tag, callable $callback, int $priority = 10): void
    {
        Hook::addAction($tag, $callback, $priority);
    }
}

if (!function_exists('do_action')) {
    /**
     * Execute all callbacks for an action
     *
     * @param string $tag
     * @param mixed ...$args
     * @return void
     */
    function do_action(string $tag, ...$args): void
    {
        Hook::doAction($tag, ...$args);
    }
}

if (!function_exists('has_action')) {
    /**
     * Check if an action has callbacks
     *
     * @param string $tag
     * @return bool
     */
    function has_action(string $tag): bool
    {
        return Hook::hasAction($tag);
    }
}

if (!function_exists('remove_action')) {
    /**
     * Remove an action hook
     *
     * @param string $tag
     * @param callable|null $callback
     * @return void
     */
    function remove_action(string $tag, ?callable $callback = null): void
    {
        Hook::removeAction($tag, $callback);
    }
}

if (!function_exists('add_filter')) {
    /**
     * Add a filter hook
     *
     * @param string $tag
     * @param callable $callback
     * @param int $priority
     * @return void
     */
    function add_filter(string $tag, callable $callback, int $priority = 10): void
    {
        Hook::addFilter($tag, $callback, $priority);
    }
}

if (!function_exists('apply_filters')) {
    /**
     * Apply all callbacks for a filter
     *
     * @param string $tag
     * @param mixed $value
     * @param mixed ...$args
     * @return mixed
     */
    function apply_filters(string $tag, mixed $value, ...$args): mixed
    {
        return Hook::applyFilters($tag, $value, ...$args);
    }
}

if (!function_exists('has_filter')) {
    /**
     * Check if a filter has callbacks
     *
     * @param string $tag
     * @return bool
     */
    function has_filter(string $tag): bool
    {
        return Hook::hasFilter($tag);
    }
}

if (!function_exists('remove_filter')) {
    /**
     * Remove a filter hook
     *
     * @param string $tag
     * @param callable|null $callback
     * @return void
     */
    function remove_filter(string $tag, ?callable $callback = null): void
    {
        Hook::removeFilter($tag, $callback);
    }
}

// ========================================
// PLUGIN UI HELPERS
// ========================================

if (!function_exists('register_menu_item')) {
    /**
     * Register a custom menu item
     *
     * @param array $item Menu item configuration
     * @return void
     */
    function register_menu_item(array $item): void
    {
        app(\App\Services\PluginUIService::class)->addMenuItem($item);
    }
}

if (!function_exists('register_page')) {
    /**
     * Register a plugin page: a GET route that renders an Inertia component,
     * usually 'Plugin::{slug}/{Page}' supplied by the plugin's UI bundle.
     *
     * Options: uri (defaults to the route name with dots as slashes),
     * permission (string, or a list meaning any of; users without it get 403),
     * props (array, or callable($request, $user) returning one), title, and
     * middleware (defaults to ['auth']).
     *
     * @param string $route Route name
     * @param string $component Inertia component name
     * @param array $options Additional options
     * @return void
     */
    function register_page(string $route, string $component, array $options = []): void
    {
        app(\App\Services\PluginUIService::class)->registerPage($route, $component, $options);
    }
}

if (!function_exists('register_dashboard_widget')) {
    /**
     * Register a dashboard widget
     *
     * @param array $widget Widget configuration
     * @return void
     */
    function register_dashboard_widget(array $widget): void
    {
        app(\App\Services\PluginUIService::class)->addDashboardWidget($widget);
    }
}

if (!function_exists('add_page_component')) {
    /**
     * Add a component to an existing page
     *
     * @param string $page Page identifier
     * @param string $slot Slot name
     * @param array $component Component configuration
     * @return void
     */
    function add_page_component(string $page, string $slot, array $component): void
    {
        app(\App\Services\PluginUIService::class)->addPageComponent($page, $slot, $component);
    }
}

if (!function_exists('register_permission')) {
    /**
     * Register a plugin permission, named "{plugin-slug}.{ability}" (for
     * example "cycle-counts.approve"). It is listed in the role editor and
     * the API token picker, admins hold it, and it can be checked like any
     * core permission ($user->hasPermission(), a page or menu `permission`).
     *
     * @param string $name "{plugin-slug}.{ability}"
     * @param string $label Shown in the role editor
     * @param string $description Shown under the label
     * @param string|null $category Role editor group; defaults to "Plugins"
     * @return void
     *
     * @throws \InvalidArgumentException When the name is not "{slug}.{ability}"
     */
    function register_permission(string $name, string $label, string $description = '', ?string $category = null): void
    {
        app(\App\Services\Plugins\PluginPermissionRegistry::class)->register($name, $label, $description, $category);
    }
}

if (!function_exists('get_page_components')) {
    /**
     * Get the components for a page slot that the current user may see:
     * entries whose `permission` they lack are left out, and callable `data`
     * is resolved for them
     *
     * @param string $page Page identifier
     * @param string $slot Slot name
     * @return array
     */
    function get_page_components(string $page, string $slot): array
    {
        return app(\App\Services\PluginUIService::class)->getVisiblePageComponents(auth()->user(), $page, $slot);
    }
}
