<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PluginUIService;
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
            $pluginUIService = app(PluginUIService::class);
            $pluginMenuItems = $pluginUIService->getMenuItems();
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'permissions' => $user ? $user->getAllPermissions() : [],
            ],
            'pluginMenuItems' => $pluginMenuItems,
            'locale' => app()->getLocale(),
            'flash' => [
                // One-time reveal of a webhook signing secret after create/
                // regenerate. Flashed by WebhookController, present for exactly
                // the one redirected request, never persisted into props.
                'newWebhookSecret' => $request->session()->get('newWebhookSecret'),
            ],
            'warehouses' => function () {
                $user = auth()->user();
                if (! $user) {
                    return [];
                }

                return $user->accessibleWarehouses()
                    ->get(['warehouses.id', 'warehouses.name', 'warehouses.code', 'warehouses.is_default'])
                    ->toArray();
            },
            'activeWarehouseId' => function () {
                return session('active_warehouse_id');
            },
        ];
    }
}
