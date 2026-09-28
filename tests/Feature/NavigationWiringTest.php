<?php

namespace Tests\Feature;

use App\Enums\Permission;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Pins the wiring between the Vue navigation and the server.
 *
 * There is no JS test runner in this project, so a nav item gated on a
 * permission that does not exist, or a route() call to a name that is not
 * registered, only shows up in a browser: the item silently disappears for
 * everyone, or the component throws when it is clicked. These tests read the
 * source and check it against the enum and the route table.
 */
class NavigationWiringTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path('resources/js/'.$path));
    }

    /**
     * Every permission name a nav item is gated on, including the array form
     * (`perm: ['a', 'b']`, shown when the user has any of them).
     *
     * @return list<string>
     */
    private function navPermissions(): array
    {
        $layout = $this->source('Layouts/AppLayout.vue');

        preg_match_all("/perm:\s*(\[[^\]]*\]|'[^']*')/", $layout, $matches);

        $names = [];
        foreach ($matches[1] as $expression) {
            preg_match_all("/'([^']+)'/", $expression, $quoted);
            array_push($names, ...$quoted[1]);
        }

        return $names;
    }

    public function test_every_nav_permission_exists_in_the_permission_enum(): void
    {
        $known = array_map(fn (Permission $p) => $p->value, Permission::cases());
        $used = $this->navPermissions();

        // Guard against a regex that silently matches nothing.
        $this->assertGreaterThanOrEqual(15, count($used), 'Expected to find the nav permission gates in AppLayout.vue.');

        foreach ($used as $name) {
            $this->assertContains($name, $known, "AppLayout.vue gates a nav item on '{$name}', which is not a Permission enum value, so the item is hidden from everyone.");
        }
    }

    /**
     * The nav gate must match what the route actually enforces, or the item
     * is shown to users who then hit a 403 (or hidden from users who could
     * open the page).
     */
    public function test_nav_items_are_gated_on_the_permission_their_route_enforces(): void
    {
        $layout = $this->source('Layouts/AppLayout.vue');

        $expected = [
            'warehouses.index' => "'view_warehouses'",
            'stock-transfers.index' => "'transfer_stock'",
            'import-export.index' => "['export_data', 'import_data']",
            'users.index' => "'view_users'",
            'roles.index' => "'view_roles'",
            'plugins.index' => "'view_plugins'",
            'customers.index' => "'view_customers'",
            'stock-adjustments.index' => "'manage_stock'",
            'activity-log.index' => "'view_activity_log'",
        ];

        foreach ($expected as $routeName => $perm) {
            $this->assertMatchesRegularExpression(
                '/route\(\''.preg_quote($routeName, '/').'\'\)[^}]*perm:\s*'.preg_quote($perm, '/').'/',
                $layout,
                "Nav item for {$routeName} should be gated on {$perm}.",
            );
        }
    }

    public function test_every_route_name_literal_in_the_frontend_is_registered(): void
    {
        $root = base_path('resources/js');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        $scanned = 0;
        $calls = 0;
        $missing = [];

        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['vue', 'js', 'ts'], true)) {
                continue;
            }
            $scanned++;

            preg_match_all("/\broute\(\s*(['\"])([^'\"]+)\\1/", (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[2] as $name) {
                $calls++;
                if (! Route::has($name)) {
                    $missing[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)).": route('{$name}')";
                }
            }
        }

        $this->assertGreaterThanOrEqual(120, $scanned, 'Expected to scan the whole resources/js tree.');
        $this->assertGreaterThanOrEqual(300, $calls, 'Expected to find the route() calls in resources/js.');
        $this->assertSame([], $missing, "route() names that are not registered:\n".implode("\n", $missing));
    }

    /**
     * Pages that were only reachable by typing the URL.
     */
    public function test_url_only_pages_are_linked_from_the_nav_or_settings_hub(): void
    {
        $layout = $this->source('Layouts/AppLayout.vue');
        $hub = $this->source('Pages/Settings/Index.vue');

        foreach (['customers.index', 'stock-adjustments.index', 'activity-log.index', 'settings.index'] as $name) {
            $this->assertStringContainsString("route('{$name}')", $layout, "{$name} is not linked from the sidebar.");
        }

        foreach ([
            'settings.account.index',
            'settings.organization.index',
            'settings.email.index',
            'webhooks.index',
            'two-factor.setup',
            'admin.update.index',
        ] as $name) {
            $this->assertStringContainsString("route('{$name}'", $hub, "{$name} is not linked from the settings hub.");
        }

        // Notification preferences live on the account page's notifications tab.
        $this->assertStringContainsString("tab: 'notifications'", $hub);
    }

    public function test_plugin_menu_items_are_rendered_in_the_sidebar(): void
    {
        $layout = $this->source('Layouts/AppLayout.vue');

        $this->assertStringContainsString('pluginMenuItems', $layout);
        $this->assertMatchesRegularExpression("/label:\s*t\('nav\.sections\.plugins'\),\s*items:\s*pluginNavItems\.value/", $layout);
    }

    public function test_sidebar_labels_are_translated_not_hard_coded(): void
    {
        $layout = $this->source('Layouts/AppLayout.vue');

        // Every section label and built-in nav item reads from nav.* in the
        // locale files; a quoted English literal would render untranslated.
        $this->assertDoesNotMatchRegularExpression("/(label|name):\s*'[A-Z]/", $layout);
        $this->assertGreaterThanOrEqual(22, preg_match_all("/name:\s*t\('nav\./", $layout));
        $this->assertSame(6, preg_match_all("/label:\s*t\('nav\.sections\./", $layout));
    }

    public function test_the_language_switcher_is_mounted(): void
    {
        $this->assertStringContainsString('<LanguageSwitcher', $this->source('Layouts/AppLayout.vue'));
    }

    public function test_install_complete_links_to_the_documentation(): void
    {
        $page = $this->source('Pages/Install/Complete.vue');

        $this->assertStringContainsString('https://inventoros.com/docs', $page);
        $this->assertStringNotContainsString('Coming Soon', $this->source('i18n/locales/en.json'));
    }
}
