<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PluginUIService;
use App\Support\PluginPageRoutes;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves pages registered with register_page().
 */
final class PluginPageController extends Controller
{
    public function __invoke(Request $request, PluginUIService $ui): Response
    {
        $name = (string) $request->route()?->defaults[PluginPageRoutes::PAGE_PARAMETER];
        $page = $ui->getCustomPage($name);

        // The route can outlive its plugin when routes are cached.
        abort_if($page === null, 404);

        $user = $request->user();

        abort_unless(PluginUIService::allows($user, $page['permission'] ?? null), 403);

        $props = $page['props'] ?? [];
        if ($props instanceof \Closure || (is_callable($props) && ! is_string($props))) {
            $props = $props($request, $user);
        }

        return Inertia::render((string) $page['component'], array_merge(
            is_array($props) ? $props : [],
            ['title' => (string) ($page['title'] ?? '')],
        ));
    }
}
