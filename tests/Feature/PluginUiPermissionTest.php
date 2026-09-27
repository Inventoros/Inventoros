<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesPluginUiUsers;
use Tests\TestCase;

/**
 * The `permission` key on add_page_component() and register_menu_item() is
 * enforced on the server: a user who lacks it never receives the entry, and a
 * callable `data` is not even evaluated for them.
 */
final class PluginUiPermissionTest extends TestCase
{
    use CreatesPluginUiUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPluginUiUsers();
    }

    public function test_a_page_component_with_a_permission_is_withheld_from_users_without_it(): void
    {
        add_page_component('products.index', 'header', [
            'plugin' => 'fixture', 'component' => 'Margins', 'permission' => 'view_reports',
        ]);
        add_page_component('products.index', 'header', [
            'plugin' => 'fixture', 'component' => 'Public',
        ]);

        $this->actingAs($this->staff)->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pluginComponents.header', 1)
                ->where('pluginComponents.header.0.component', 'Public'));

        $this->actingAs($this->admin)->get(route('products.index'))
            ->assertInertia(fn ($page) => $page->has('pluginComponents.header', 2));
    }

    public function test_a_permission_list_means_any_of(): void
    {
        add_page_component('products.index', 'footer', [
            'plugin' => 'fixture', 'component' => 'Either', 'permission' => ['view_reports', 'view_orders'],
        ]);

        $this->actingAs($this->staff)->get(route('products.index'))
            ->assertInertia(fn ($page) => $page->has('pluginComponents.footer', 1));
    }

    public function test_callable_data_is_only_evaluated_for_users_who_may_see_the_component(): void
    {
        $calls = 0;
        add_page_component('products.index', 'header', [
            'plugin' => 'fixture',
            'component' => 'Margins',
            'permission' => 'view_reports',
            'data' => function ($user) use (&$calls) {
                $calls++;

                return ['margin' => 42, 'viewer' => $user->id];
            },
        ]);

        $this->actingAs($this->staff)->get(route('products.index'))->assertOk();
        $this->assertSame(0, $calls);

        $this->actingAs($this->admin)->get(route('products.index'))
            ->assertInertia(fn ($page) => $page
                ->where('pluginComponents.header.0.data', ['margin' => 42, 'viewer' => $this->admin->id]));
        $this->assertSame(1, $calls);
    }

    public function test_a_menu_item_with_a_permission_is_not_shared_with_users_without_it(): void
    {
        register_menu_item(['label' => 'Margins', 'url' => 'https://example.com/margins', 'permission' => 'view_reports']);
        register_menu_item(['label' => 'Docs', 'url' => 'https://example.com/docs']);
        register_menu_item([
            'label' => 'Tools',
            'url' => 'https://example.com/tools',
            'submenu' => [
                ['label' => 'Secret', 'url' => 'https://example.com/secret', 'permission' => 'manage_plugins'],
                ['label' => 'Open', 'url' => 'https://example.com/open'],
            ],
        ]);

        $this->actingAs($this->staff)->get(route('products.index'))
            ->assertInertia(fn ($page) => $page
                ->has('pluginMenuItems', 2)
                ->where('pluginMenuItems.0.label', 'Docs')
                ->has('pluginMenuItems.1.submenu', 1)
                ->where('pluginMenuItems.1.submenu.0.label', 'Open'));

        $this->actingAs($this->admin)->get(route('products.index'))
            ->assertInertia(fn ($page) => $page->has('pluginMenuItems', 3));
    }
}
