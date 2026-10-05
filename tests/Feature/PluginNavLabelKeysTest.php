<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesPluginUiUsers;
use Tests\Feature\Concerns\InteractsWithFixturePlugins;
use Tests\TestCase;

/**
 * Plugin menu items (and submenu items and dashboard widgets) can name a
 * translation key under plugins.{slug}. The browser resolves it from the
 * plugin's own messages and falls back to the plain label, so plain strings
 * keep working. A key outside the plugin's namespace is ignored.
 */
final class PluginNavLabelKeysTest extends TestCase
{
    use CreatesPluginUiUsers;
    use InteractsWithFixturePlugins;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPluginUiUsers();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixturePlugins();
        parent::tearDown();
    }

    public function test_a_menu_item_shares_its_label_key_and_keeps_its_plain_label_as_the_fallback(): void
    {
        register_menu_item([
            'label' => 'Counts',
            'label_key' => 'plugins.cycle-counts.nav.counts',
            'url' => 'https://example.com/counts',
            'submenu' => [
                ['label' => 'Schedules', 'label_key' => 'plugins.cycle-counts.nav.schedules', 'url' => 'https://example.com/schedules'],
            ],
        ]);
        register_menu_item(['label' => 'Docs', 'url' => 'https://example.com/docs']);

        $this->actingAs($this->staff)->get(route('products.index'))
            ->assertInertia(fn ($page) => $page
                ->where('pluginMenuItems.0.label', 'Counts')
                ->where('pluginMenuItems.0.label_key', 'plugins.cycle-counts.nav.counts')
                ->where('pluginMenuItems.0.submenu.0.label_key', 'plugins.cycle-counts.nav.schedules')
                ->where('pluginMenuItems.1.label', 'Docs')
                ->where('pluginMenuItems.1.label_key', null));
    }

    public function test_a_label_key_outside_the_plugins_namespace_is_ignored(): void
    {
        register_menu_item(['label' => 'Sneaky', 'label_key' => 'nav.settings', 'url' => 'https://example.com/x']);
        register_menu_item(['label' => 'Broken', 'label_key' => ['not', 'a', 'string'], 'url' => 'https://example.com/y']);

        $this->actingAs($this->staff)->get(route('products.index'))
            ->assertInertia(fn ($page) => $page
                ->where('pluginMenuItems.0.label_key', null)
                ->where('pluginMenuItems.1.label_key', null));
    }

    public function test_a_plugin_may_only_name_keys_under_its_own_slug(): void
    {
        $slug = $this->makeFixturePlugin(files: ['Plugin.php' => <<<'PHP'
            <?php
            return function (string $slug) {
                register_menu_item(['label' => 'Own', 'label_key' => "plugins.{$slug}.nav.own", 'url' => 'https://example.com/own']);
                register_menu_item(['label' => 'Other', 'label_key' => 'plugins.someone-else.nav.theirs', 'url' => 'https://example.com/other']);
                register_dashboard_widget(['id' => 'w', 'title' => 'Widget', 'title_key' => "plugins.{$slug}.widget.title", 'plugin' => $slug, 'component' => 'W']);
            };
            PHP]);

        app(PluginService::class)->activatePlugin($slug);

        $this->actingAs($this->staff)->get(route('products.index'))
            ->assertInertia(fn ($page) => $page
                ->where('pluginMenuItems.0.label_key', "plugins.{$slug}.nav.own")
                ->where('pluginMenuItems.1.label_key', null));

        $this->actingAs($this->staff)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('pluginWidgets.0.title', 'Widget')
                ->where('pluginWidgets.0.title_key', "plugins.{$slug}.widget.title"));
    }
}
