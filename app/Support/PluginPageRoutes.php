<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\PluginPageController;
use App\Services\PluginUIService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Adds a GET route for every page registered with register_page().
 *
 * Called from routes/web.php, after active plugins have loaded. Each route
 * points at PluginPageController with the page's route name as a default, so
 * routes stay cacheable; the page itself (component, permission, props) is
 * looked up at request time, and a cached route whose plugin is no longer
 * active answers 404.
 *
 * A page may not reuse an existing route name, so a plugin cannot take over a
 * core route or link.
 */
final class PluginPageRoutes
{
    public const PAGE_PARAMETER = '_plugin_page';

    public static function register(): void
    {
        foreach (app(PluginUIService::class)->getCustomPages() as $name => $page) {
            if (Route::has($name)) {
                Log::warning('Plugin page skipped: the route name is already taken', ['route' => $name]);

                continue;
            }

            $uri = is_string($page['uri'] ?? null) && trim($page['uri'], '/') !== ''
                ? trim($page['uri'], '/')
                : str_replace('.', '/', $name);

            $middleware = array_values(array_filter((array) ($page['middleware'] ?? []), 'is_string'));

            Route::get($uri, PluginPageController::class)
                ->middleware($middleware)
                ->defaults(self::PAGE_PARAMETER, $name)
                ->name($name);
        }
    }
}
