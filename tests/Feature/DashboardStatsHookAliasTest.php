<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plugin guide documented a `dashboard_stats` action while the dashboard
 * only fired `dashboard_stats_calculated`. Both names must fire, with the same
 * (filtered) stats.
 */
final class DashboardStatsHookAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_fires_both_stats_action_names_with_filtered_stats(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        $organization = Organization::create([
            'name' => 'Org', 'email' => 'org@example.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
            'organization_id' => $organization->id, 'role' => 'admin',
        ]);

        add_filter('dashboard_stats_data', function (array $stats) {
            $stats['plugin_metric'] = 42;

            return $stats;
        });

        $seen = [];
        add_action('dashboard_stats_calculated', function (array $stats, $user) use (&$seen) {
            $seen['dashboard_stats_calculated'] = [$stats['plugin_metric'] ?? null, $user->id];
        });
        add_action('dashboard_stats', function (array $stats, $user) use (&$seen) {
            $seen['dashboard_stats'] = [$stats['plugin_metric'] ?? null, $user->id];
        });

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $this->assertSame([
            'dashboard_stats_calculated' => [42, $admin->id],
            'dashboard_stats' => [42, $admin->id],
        ], $seen);
    }
}
