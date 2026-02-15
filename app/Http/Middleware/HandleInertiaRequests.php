<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Middleware for handling Inertia.js requests.
 *
 * Manages asset versioning and shares common props across all
 * Inertia-powered pages including auth state and plugin menu items.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // Get plugin menu items
        $pluginMenuItems = [];
        if ($user) {
            $pluginUIService = app(\App\Services\PluginUIService::class);
            $pluginMenuItems = $pluginUIService->getMenuItems();
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'permissions' => $user ? $user->getAllPermissions() : [],
            ],
            'pluginMenuItems' => $pluginMenuItems,
        ];
    }
}
