<?php

namespace Tests\Unit;

use App\Services\PluginUIService;
use Tests\TestCase;

class PluginUIServiceTest extends TestCase
{
    protected PluginUIService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PluginUIService();
    }

    public function test_can_add_menu_item(): void
    {
        $this->service->addMenuItem([
            'label' => 'Test Menu',
            'route' => 'test.route',
            'icon' => 'test-icon',
            'position' => 50,
        ]);

        $menuItems = $this->service->getMenuItems();

        $this->assertCount(1, $menuItems);
        $this->assertEquals('Test Menu', $menuItems[0]['label']);
        $this->assertEquals('test.route', $menuItems[0]['route']);
        $this->assertEquals('test-icon', $menuItems[0]['icon']);
        $this->assertEquals(50, $menuItems[0]['position']);
    }

    public function test_menu_item_has_default_values(): void
    {
        $this->service->addMenuItem([
            'label' => 'Test Menu',
        ]);

        $menuItems = $this->service->getMenuItems();

        $this->assertNull($menuItems[0]['route']);
        $this->assertNull($menuItems[0]['url']);
        $this->assertNull($menuItems[0]['icon']);
        $this->assertNull($menuItems[0]['permission']);
        $this->assertEquals(100, $menuItems[0]['position']);
        $this->assertNull($menuItems[0]['parent']);
        $this->assertNull($menuItems[0]['badge']);
        $this->assertEquals([], $menuItems[0]['active_routes']);
        $this->assertEquals([], $menuItems[0]['submenu']);
    }

    public function test_can_add_multiple_menu_items(): void
    {
        $this->service->addMenuItems([
            ['label' => 'Menu 1', 'position' => 10],
            ['label' => 'Menu 2', 'position' => 20],
            ['label' => 'Menu 3', 'position' => 5],
        ]);

        $menuItems = $this->service->getMenuItems();

        $this->assertCount(3, $menuItems);
        // Should be sorted by position
        $this->assertEquals('Menu 3', $menuItems[0]['label']);
        $this->assertEquals('Menu 1', $menuItems[1]['label']);
        $this->assertEquals('Menu 2', $menuItems[2]['label']);
    }

    public function test_menu_items_are_sorted_by_position(): void
    {
        $this->service->addMenuItem(['label' => 'Third', 'position' => 300]);
        $this->service->addMenuItem(['label' => 'First', 'position' => 100]);
        $this->service->addMenuItem(['label' => 'Second', 'position' => 200]);

        $menuItems = $this->service->getMenuItems();

        $this->assertEquals('First', $menuItems[0]['label']);
        $this->assertEquals('Second', $menuItems[1]['label']);
        $this->assertEquals('Third', $menuItems[2]['label']);
    }

    public function test_can_add_submenu_item(): void
    {
        $this->service->addMenuItem([
            'label' => 'Parent Menu',
            'route' => 'parent.route',
        ]);

        $this->service->addSubmenuItem('Parent Menu', [
            'label' => 'Submenu Item',
            'route' => 'submenu.route',
            'icon' => 'submenu-icon',
        ]);

        $menuItems = $this->service->getMenuItems();

        $this->assertCount(1, $menuItems);
        $this->assertArrayHasKey('submenu', $menuItems[0]);
        $this->assertCount(1, $menuItems[0]['submenu']);
        $this->assertEquals('Submenu Item', $menuItems[0]['submenu'][0]['label']);
        $this->assertEquals('submenu.route', $menuItems[0]['submenu'][0]['route']);
        $this->assertEquals('submenu-icon', $menuItems[0]['submenu'][0]['icon']);
    }

    public function test_can_add_multiple_submenu_items_to_same_parent(): void
    {
        $this->service->addMenuItem([
            'label' => 'Settings',
            'route' => 'settings.index',
        ]);

        $this->service->addSubmenuItem('Settings', [
            'label' => 'General',
            'route' => 'settings.general',
        ]);

        $this->service->addSubmenuItem('Settings', [
            'label' => 'Advanced',
            'route' => 'settings.advanced',
        ]);

        $menuItems = $this->service->getMenuItems();

        $this->assertCount(2, $menuItems[0]['submenu']);
        $this->assertEquals('General', $menuItems[0]['submenu'][0]['label']);
        $this->assertEquals('Advanced', $menuItems[0]['submenu'][1]['label']);
    }

    public function test_submenu_item_has_default_values(): void
    {
        $this->service->addMenuItem(['label' => 'Parent']);
        $this->service->addSubmenuItem('Parent', ['label' => 'Child']);

        $menuItems = $this->service->getMenuItems();
        $submenuItem = $menuItems[0]['submenu'][0];

        $this->assertEquals('Child', $submenuItem['label']);
        $this->assertNull($submenuItem['route']);
        $this->assertNull($submenuItem['url']);
        $this->assertNull($submenuItem['icon']);
        $this->assertNull($submenuItem['permission']);
        $this->assertEquals([], $submenuItem['active_routes']);
    }

    public function test_adding_submenu_to_nonexistent_parent_does_nothing(): void
    {
        $this->service->addMenuItem(['label' => 'Parent']);
        $this->service->addSubmenuItem('Nonexistent Parent', ['label' => 'Child']);

        $menuItems = $this->service->getMenuItems();

        $this->assertCount(0, $menuItems[0]['submenu']);
    }

    public function test_can_add_menu_item_with_submenu_directly(): void
    {
        $this->service->addMenuItem([
            'label' => 'Parent',
            'route' => 'parent.route',
            'submenu' => [
                ['label' => 'Child 1', 'route' => 'child1.route'],
                ['label' => 'Child 2', 'route' => 'child2.route'],
            ],
        ]);

        $menuItems = $this->service->getMenuItems();

        $this->assertCount(2, $menuItems[0]['submenu']);
        $this->assertEquals('Child 1', $menuItems[0]['submenu'][0]['label']);
        $this->assertEquals('Child 2', $menuItems[0]['submenu'][1]['label']);
    }

    public function test_can_register_custom_page(): void
    {
        $this->service->registerPage('custom.page', 'CustomComponent', [
            'title' => 'Custom Page',
            'permission' => 'view_custom',
        ]);

        $pages = $this->service->getCustomPages();

        $this->assertCount(1, $pages);
        $this->assertEquals('custom.page', $pages['custom.page']['route']);
        $this->assertEquals('CustomComponent', $pages['custom.page']['component']);
        $this->assertEquals('Custom Page', $pages['custom.page']['title']);
        $this->assertEquals('view_custom', $pages['custom.page']['permission']);
    }

    public function test_custom_page_has_default_values(): void
    {
        $this->service->registerPage('test.page', 'TestComponent');

        $pages = $this->service->getCustomPages();
        $page = $pages['test.page'];

        $this->assertEquals(['auth'], $page['middleware']);
        $this->assertNull($page['permission']);
        $this->assertEquals('Custom Page', $page['title']);
    }

    public function test_can_add_dashboard_widget(): void
    {
        $this->service->addDashboardWidget([
            'title' => 'Test Widget',
            'component' => 'TestWidget',
            'width' => 'half',
            'position' => 50,
        ]);

        $widgets = $this->service->getDashboardWidgets();

        $this->assertCount(1, $widgets);
        $this->assertEquals('Test Widget', $widgets[0]['title']);
        $this->assertEquals('TestWidget', $widgets[0]['component']);
        $this->assertEquals('half', $widgets[0]['width']);
        $this->assertEquals(50, $widgets[0]['position']);
    }

    public function test_dashboard_widgets_are_sorted_by_position(): void
    {
        $this->service->addDashboardWidget(['title' => 'Third', 'position' => 300]);
        $this->service->addDashboardWidget(['title' => 'First', 'position' => 100]);
        $this->service->addDashboardWidget(['title' => 'Second', 'position' => 200]);

        $widgets = $this->service->getDashboardWidgets();

        $this->assertEquals('First', $widgets[0]['title']);
        $this->assertEquals('Second', $widgets[1]['title']);
        $this->assertEquals('Third', $widgets[2]['title']);
    }

    public function test_can_add_page_component(): void
    {
        $this->service->addPageComponent('product.show', 'sidebar', [
            'component' => 'CustomSidebarWidget',
            'data' => ['key' => 'value'],
            'position' => 50,
        ]);

        $components = $this->service->getPageComponents('product.show', 'sidebar');

        $this->assertCount(1, $components);
        $this->assertEquals('CustomSidebarWidget', $components[0]['component']);
        $this->assertEquals(['key' => 'value'], $components[0]['data']);
        $this->assertEquals(50, $components[0]['position']);
    }

    public function test_can_add_multiple_components_to_same_page_slot(): void
    {
        $this->service->addPageComponent('product.show', 'tabs', [
            'component' => 'Tab1',
            'position' => 20,
        ]);

        $this->service->addPageComponent('product.show', 'tabs', [
            'component' => 'Tab2',
            'position' => 10,
        ]);

        $components = $this->service->getPageComponents('product.show', 'tabs');

        $this->assertCount(2, $components);
        // Should be sorted by position
        $this->assertEquals('Tab2', $components[0]['component']);
        $this->assertEquals('Tab1', $components[1]['component']);
    }

    public function test_get_page_components_returns_empty_for_nonexistent_slot(): void
    {
        $components = $this->service->getPageComponents('product.show', 'nonexistent');

        $this->assertEquals([], $components);
    }

    public function test_can_get_all_page_components(): void
    {
        $this->service->addPageComponent('product.show', 'sidebar', [
            'component' => 'Sidebar1',
        ]);

        $this->service->addPageComponent('product.show', 'tabs', [
            'component' => 'Tab1',
        ]);

        $allComponents = $this->service->getAllPageComponents('product.show');

        $this->assertArrayHasKey('sidebar', $allComponents);
        $this->assertArrayHasKey('tabs', $allComponents);
        $this->assertCount(1, $allComponents['sidebar']);
        $this->assertCount(1, $allComponents['tabs']);
    }

    public function test_clear_removes_all_ui_elements(): void
    {
        $this->service->addMenuItem(['label' => 'Test Menu']);
        $this->service->registerPage('test.page', 'TestComponent');
        $this->service->addDashboardWidget(['title' => 'Test Widget']);
        $this->service->addPageComponent('test.page', 'slot', ['component' => 'Test']);

        $this->service->clear();

        $this->assertEquals([], $this->service->getMenuItems());
        $this->assertEquals([], $this->service->getCustomPages());
        $this->assertEquals([], $this->service->getDashboardWidgets());
        $this->assertEquals([], $this->service->getPageComponents('test.page', 'slot'));
    }

    public function test_menu_items_with_permission_are_preserved(): void
    {
        $this->service->addMenuItem([
            'label' => 'Admin Menu',
            'route' => 'admin.index',
            'permission' => 'view_admin',
        ]);

        $menuItems = $this->service->getMenuItems();

        $this->assertEquals('view_admin', $menuItems[0]['permission']);
    }

    public function test_menu_items_with_badge_are_preserved(): void
    {
        $this->service->addMenuItem([
            'label' => 'Notifications',
            'route' => 'notifications.index',
            'badge' => '5',
        ]);

        $menuItems = $this->service->getMenuItems();

        $this->assertEquals('5', $menuItems[0]['badge']);
    }

    public function test_menu_items_with_active_routes_are_preserved(): void
    {
        $this->service->addMenuItem([
            'label' => 'Products',
            'route' => 'products.index',
            'active_routes' => ['products.*', 'categories.*'],
        ]);

        $menuItems = $this->service->getMenuItems();

        $this->assertEquals(['products.*', 'categories.*'], $menuItems[0]['active_routes']);
    }
}
