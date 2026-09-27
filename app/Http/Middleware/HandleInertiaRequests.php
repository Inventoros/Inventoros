<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ApprovalService;
use App\Services\PluginService;
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
            // Pre-built runtime UI bundles of active plugins; app.js import()s
            // each entry so ZIP-installed plugins get UI without an npm build.
            'pluginAssets' => fn () => $user ? app(PluginService::class)->runtimeAssets() : [],
            'locale' => app()->getLocale(),
            'flash' => [
                // Controllers redirect with ->with('success'|'error'|...) in
                // hundreds of places, and none of it reached the browser
                // because it was never shared. The keys below are the four the
                // application actually flashes; anything else stays server-side.
                //
                // Values are passed through untouched. Most are strings and are
                // rendered globally by FlashMessages; the importer flashes a
                // structured ['message' => ..., 'stats' => ...] payload that its
                // own page renders, so this must not coerce or reshape them.
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'status' => $request->session()->get('status'),

                // One-time reveal of a webhook signing secret after create/
                // regenerate. Flashed by WebhookController, present for exactly
                // the one redirected request, never persisted into props.
                'newWebhookSecret' => $request->session()->get('newWebhookSecret'),

                // One-time reveal of a new API token's plaintext, flashed by
                // ApiTokenController::store for the one redirected request.
                'newApiToken' => $request->session()->get('newApiToken'),
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
            // Badge on the "Approvals" nav item: requests this user can decide.
            'pendingApprovalsCount' => function () use ($user) {
                if (! $user) {
                    return 0;
                }

                $canApprove = $user->hasAnyPermission([
                    'approve_purchase_orders', 'approve_stock_adjustments', 'approve_stock_transfers',
                ]);

                return $canApprove ? app(ApprovalService::class)->pendingCountFor($user) : 0;
            },
        ];
    }
}
