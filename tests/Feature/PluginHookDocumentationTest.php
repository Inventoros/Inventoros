<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\HookRegistry;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Keeps the plugin hook documentation honest.
 *
 * The guides used to document filters that never ran (`product_display_name`,
 * `product_search_query`, ...) and an action under the wrong name
 * (`dashboard_stats`). These checks tie three things together:
 *
 *  - the hooks the code under app/ actually fires,
 *  - HookRegistry, the machine-readable list of them,
 *  - the hooks the plugin guides and the example plugin mention.
 */
final class PluginHookDocumentationTest extends TestCase
{
    /**
     * Documented hooks whose call site lands in a separate change set. Each
     * entry must be removed once the hook fires on main.
     *
     * `order_total_calculation` is applied inside OrderService's total
     * computation by the order payments and discounts work; adding it here as
     * well would apply the filter twice.
     *
     * @var array<int, string>
     */
    private const PENDING_CALL_SITES = ['order_total_calculation'];

    /** @var array<int, string> */
    private const GUIDES = [
        'docs/PLUGIN_DEVELOPMENT.md',
        'docs/site/sections/plugins.md',
        'plugins/hello-world/Plugin.php',
        'plugins/hello-world/README.md',
        'plugins/README.md',
    ];

    /**
     * Hook names fired under app/, with interpolated parts ("plugin_activated_{$slug}")
     * normalised to a wildcard.
     *
     * @return array<int, string>
     */
    private function firedHooks(): array
    {
        $hooks = [];

        foreach (File::allFiles(base_path('app')) as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'HookRegistry.php')) {
                continue;
            }

            preg_match_all(
                '/\b(?:do_action|apply_filters)\(\s*([\'"])([^\'"]+)\1/',
                $file->getContents(),
                $matches
            );

            foreach ($matches[2] as $name) {
                if ($name === '$tag') {
                    continue;
                }
                $hooks[] = $this->normalise($name);
            }
        }

        return array_values(array_unique($hooks));
    }

    /**
     * @return array<int, string>
     */
    private function registeredHooks(): array
    {
        return array_map(
            fn (string $name) => $this->normalise($name),
            array_merge(array_keys(HookRegistry::getActions()), array_keys(HookRegistry::getFilters()))
        );
    }

    private function normalise(string $name): string
    {
        return (string) preg_replace('/\{\$?[A-Za-z_]+\}/', '{*}', $name);
    }

    public function test_every_registered_hook_is_fired_by_the_application(): void
    {
        $fired = $this->firedHooks();
        $expected = array_diff($this->registeredHooks(), self::PENDING_CALL_SITES);

        $this->assertSame([], array_values(array_diff($expected, $fired)),
            'HookRegistry lists hooks that nothing under app/ fires.');
    }

    public function test_every_fired_hook_is_registered(): void
    {
        $this->assertSame([], array_values(array_diff($this->firedHooks(), $this->registeredHooks())),
            'Hooks fired under app/ are missing from HookRegistry.');
    }

    public function test_every_hook_named_in_the_guides_is_registered(): void
    {
        $registered = $this->registeredHooks();
        $unknown = [];

        foreach (self::GUIDES as $guide) {
            $contents = File::get(base_path($guide));

            // add_action('x' / add_filter('x' / has_filter('x' ... and "#### `x`" headings.
            preg_match_all('/\b(?:add_action|add_filter|do_action|apply_filters|has_action|has_filter|remove_action|remove_filter)\(\s*\'([a-z0-9_{}\-]+)\'/', $contents, $calls);
            preg_match_all('/^#{2,4} `([a-z0-9_{}\-]+)`/m', $contents, $headings);

            foreach (array_merge($calls[1], $headings[1]) as $name) {
                if (str_starts_with($name, 'my_plugin_') || $name === 'hook_name' || $name === 'filter_name') {
                    continue; // The guide's own illustrations of custom hook names.
                }

                $normalised = (string) preg_replace('/_(my-plugin|hello-world)$/', '_{*}', $this->normalise($name));
                $normalised = (string) preg_replace('/^report_query_[a-z_]+$/', 'report_query_{*}', $normalised);

                if (! in_array($normalised, $registered, true)) {
                    $unknown[] = "{$guide}: {$name}";
                }
            }
        }

        $this->assertSame([], $unknown, 'The guides mention hooks the application does not fire.');
    }

    public function test_the_full_guide_documents_every_registered_hook(): void
    {
        $guide = File::get(base_path('docs/PLUGIN_DEVELOPMENT.md'));
        $missing = [];

        foreach (array_merge(array_keys(HookRegistry::getActions()), array_keys(HookRegistry::getFilters())) as $name) {
            if (! str_contains($guide, '`'.$name.'`')) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'docs/PLUGIN_DEVELOPMENT.md does not document these hooks.');
    }
}
