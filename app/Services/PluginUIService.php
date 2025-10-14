<?php

namespace App\Services;

/**
 * Service for plugins to register custom UI elements (pages, menus, etc.)
 */
class PluginUIService
{
    protected array $menuItems = [];
    protected array $customPages = [];
    protected array $dashboardWidgets = [];
    protected array $pageComponents = [];

    /**
     * Register a custom menu item
     *
     * @param array $item Menu item configuration
     * @return void
     */
    public function addMenuItem(array $item): void
    {
        $defaults = [
            'label' => 'Custom Item',
            'route' => null,
            'url' => null,
            'icon' => null,
            'permission' => null,
            'position' => 100,
            'parent' => null,
            'badge' => null,
            'active_routes' => [],
        ];

        $this->menuItems[] = array_merge($defaults, $item);
    }

    /**
     * Register multiple menu items at once
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
     * Get all registered menu items
     *
     * @return array
     */
    public function getMenuItems(): array
    {
        // Sort by position
        usort($this->menuItems, fn($a, $b) => $a['position'] <=> $b['position']);
        return $this->menuItems;
    }

    /**
     * Register a custom page route
     *
     * @param string $route Route name
     * @param string $component Inertia component name
     * @param array $options Additional options
     * @return void
     */
    public function registerPage(string $route, string $component, array $options = []): void
    {
        $defaults = [
            'middleware' => ['auth'],
            'permission' => null,
            'title' => 'Custom Page',
        ];

        $this->customPages[$route] = array_merge($defaults, [
            'route' => $route,
            'component' => $component,
        ], $options);
    }

    /**
     * Get all registered custom pages
     *
     * @return array
     */
    public function getCustomPages(): array
    {
        return $this->customPages;
    }

    /**
     * Register a dashboard widget
     *
     * @param array $widget Widget configuration
     * @return void
     */
    public function addDashboardWidget(array $widget): void
    {
        $defaults = [
            'id' => uniqid('widget_'),
            'title' => 'Custom Widget',
            'component' => null,
            'data' => [],
            'position' => 100,
            'width' => 'full', // 'full', 'half', 'third', 'quarter'
            'permission' => null,
        ];

        $this->dashboardWidgets[] = array_merge($defaults, $widget);
    }

    /**
     * Get all registered dashboard widgets
     *
     * @return array
     */
    public function getDashboardWidgets(): array
    {
        // Sort by position
        usort($this->dashboardWidgets, fn($a, $b) => $a['position'] <=> $b['position']);
        return $this->dashboardWidgets;
    }

    /**
     * Register a component to be injected into an existing page
     *
     * @param string $page Page identifier (e.g., 'product.show', 'dashboard', 'product.index')
     * @param string $slot Slot name (e.g., 'sidebar', 'header', 'footer', 'tabs')
     * @param array $component Component configuration
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
     * Get components registered for a specific page and slot
     *
     * @param string $page Page identifier
     * @param string $slot Slot name
     * @return array
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
     * Get all components for a specific page
     *
     * @param string $page Page identifier
     * @return array
     */
    public function getAllPageComponents(string $page): array
    {
        return $this->pageComponents[$page] ?? [];
    }

    /**
     * Clear all registered UI elements (useful for testing)
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
