<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Tests\TestCase;

class DashboardWidgetPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => [],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    public function test_user_can_save_dashboard_widget_preferences(): void
    {
        $widgets = [
            'stats_overview' => true,
            'revenue_chart' => false,
            'stock_movements' => true,
            'low_stock_alerts' => true,
            'recent_orders' => false,
            'recent_products' => true,
            'top_products' => false,
            'stock_by_category' => true,
            'reorder_suggestions' => false,
        ];

        $response = $this->actingAs($this->admin)
            ->patchJson(route('settings.dashboard-widgets.update'), [
                'widgets' => $widgets,
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->admin->refresh();
        $this->assertEquals($widgets, $this->admin->dashboard_widgets);
    }

    public function test_dashboard_returns_widget_preferences(): void
    {
        $widgets = [
            'stats_overview' => true,
            'revenue_chart' => false,
            'stock_movements' => true,
            'low_stock_alerts' => true,
            'recent_orders' => true,
            'recent_products' => true,
            'top_products' => true,
            'stock_by_category' => true,
            'reorder_suggestions' => false,
        ];

        $this->admin->update(['dashboard_widgets' => $widgets]);

        $response = $this->actingAs($this->admin)
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Dashboard')
                ->has('widgetPreferences')
                ->where('widgetPreferences', $widgets)
        );
    }

    public function test_dashboard_returns_all_widgets_visible_by_default(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Dashboard')
                ->has('widgetPreferences')
                ->where('widgetPreferences.stats_overview', true)
                ->where('widgetPreferences.revenue_chart', true)
                ->where('widgetPreferences.stock_movements', true)
                ->where('widgetPreferences.low_stock_alerts', true)
                ->where('widgetPreferences.recent_orders', true)
                ->where('widgetPreferences.recent_products', true)
                ->where('widgetPreferences.top_products', true)
                ->where('widgetPreferences.stock_by_category', true)
                ->where('widgetPreferences.reorder_suggestions', true)
        );
    }

    public function test_saving_invalid_widget_keys_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)
            ->patchJson(route('settings.dashboard-widgets.update'), [
                'widgets' => [
                    'invalid_widget' => true,
                    'stats_overview' => true,
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_guest_cannot_save_widget_preferences(): void
    {
        $response = $this->patchJson(route('settings.dashboard-widgets.update'), [
            'widgets' => ['stats_overview' => true],
        ]);

        $response->assertStatus(401);
    }

    public function test_widget_preferences_persist_across_requests(): void
    {
        $widgets = [
            'stats_overview' => true,
            'revenue_chart' => false,
            'stock_movements' => false,
            'low_stock_alerts' => true,
            'recent_orders' => true,
            'recent_products' => false,
            'top_products' => true,
            'stock_by_category' => false,
            'reorder_suggestions' => true,
        ];

        // Save preferences
        $this->actingAs($this->admin)
            ->patchJson(route('settings.dashboard-widgets.update'), [
                'widgets' => $widgets,
            ]);

        // Load dashboard and verify
        $response = $this->actingAs($this->admin)
            ->get(route('dashboard'));

        $response->assertInertia(fn ($page) =>
            $page->where('widgetPreferences.revenue_chart', false)
                ->where('widgetPreferences.stock_movements', false)
                ->where('widgetPreferences.recent_products', false)
                ->where('widgetPreferences.stock_by_category', false)
        );
    }
}
