<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\CreatesPluginUiUsers;
use Tests\TestCase;

/**
 * register_dashboard_widget() widgets reach the dashboard, gated like the
 * built-in figures: a widget the user may not see is absent from the payload
 * (not rendered empty), and its data is never computed for them.
 */
final class DashboardPluginWidgetsTest extends TestCase
{
    use CreatesPluginUiUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPluginUiUsers();
    }

    public function test_widgets_are_passed_to_the_dashboard_in_position_order(): void
    {
        register_dashboard_widget(['id' => 'second', 'title' => 'Second', 'plugin' => 'fixture', 'component' => 'B', 'position' => 20]);
        register_dashboard_widget([
            'id' => 'first', 'title' => 'First', 'plugin' => 'fixture', 'component' => 'A',
            'position' => 10, 'width' => 'half', 'data' => ['count' => 3],
        ]);

        $this->actingAs($this->staff)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pluginWidgets', 2)
                ->where('pluginWidgets.0.id', 'first')
                ->where('pluginWidgets.0.title', 'First')
                ->where('pluginWidgets.0.plugin', 'fixture')
                ->where('pluginWidgets.0.component', 'A')
                ->where('pluginWidgets.0.width', 'half')
                ->where('pluginWidgets.0.data', ['count' => 3])
                ->where('pluginWidgets.1.id', 'second'));
    }

    public function test_a_widget_the_user_may_not_see_is_absent_and_its_data_is_not_computed(): void
    {
        $calls = 0;
        register_dashboard_widget([
            'id' => 'margins', 'title' => 'Margins', 'plugin' => 'fixture', 'component' => 'Margins',
            'permission' => 'view_reports',
            'data' => function ($user) use (&$calls) {
                $calls++;

                return ['margin' => 0.4];
            },
        ]);

        $this->actingAs($this->staff)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('pluginWidgets', []));
        $this->assertSame(0, $calls);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->has('pluginWidgets', 1)
                ->where('pluginWidgets.0.data', ['margin' => 0.4]));
        $this->assertSame(1, $calls);
    }

    public function test_the_permission_key_is_not_sent_to_the_browser(): void
    {
        register_dashboard_widget(['id' => 'w', 'title' => 'W', 'plugin' => 'fixture', 'component' => 'W', 'permission' => 'view_products']);

        $this->actingAs($this->staff)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->missing('pluginWidgets.0.permission'));
    }

    public function test_a_widget_without_a_component_is_skipped(): void
    {
        register_dashboard_widget(['id' => 'broken', 'title' => 'Broken']);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('pluginWidgets', []));
    }

    public function test_the_dashboard_renders_plugin_widgets_and_the_widgets_slot(): void
    {
        $dashboard = File::get(resource_path('js/Pages/Dashboard.vue'));

        $this->assertStringContainsString('<PluginWidgets', $dashboard);
        $this->assertStringContainsString('slot="widgets"', $dashboard);
    }
}
