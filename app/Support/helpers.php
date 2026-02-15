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
     * Register a custom page route
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

if (!function_exists('get_page_components')) {
    /**
     * Get components for a specific page and slot
     *
     * @param string $page Page identifier
     * @param string $slot Slot name
     * @return array
     */
    function get_page_components(string $page, string $slot): array
    {
        return app(\App\Services\PluginUIService::class)->getPageComponents($page, $slot);
    }
}
