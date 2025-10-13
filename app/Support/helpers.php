<?php

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
