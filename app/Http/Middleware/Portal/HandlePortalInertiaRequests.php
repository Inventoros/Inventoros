<?php

declare(strict_types=1);

namespace App\Http\Middleware\Portal;

use App\Models\CustomerContact;
use App\Support\Portal\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Middleware;

/**
 * Inertia shared props for the customer portal.
 *
 * Replaces the staff HandleInertiaRequests on portal routes: nothing about
 * staff users, permissions, warehouses or plugins is shared, only the portal
 * organization's name and the signed-in contact.
 */
final class HandlePortalInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Start from an empty set of shared props. Inertia keeps shared props on
     * a container singleton, so in a long-lived process (Octane, the test
     * runner) a staff request's props could otherwise carry over into a
     * portal response.
     */
    public function handle(Request $request, Closure $next)
    {
        Inertia::flushShared();

        return parent::handle($request, $next);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $organization = $request->attributes->get(PortalContext::ORGANIZATION);

        return [
            ...parent::share($request),
            // Present so shared components that read auth.user see a guest.
            'auth' => [
                'user' => null,
                'permissions' => [],
            ],
            'locale' => app()->getLocale(),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'status' => $request->session()->get('status'),
            ],
            'portal' => [
                'organization' => $organization ? [
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'email' => $organization->email,
                ] : null,
                'contact' => function () use ($organization) {
                    $contact = Auth::guard('customer')->user();

                    if (! $contact instanceof CustomerContact
                        || ! $organization
                        || (int) $contact->organization_id !== (int) $organization->id) {
                        return null;
                    }

                    $customer = $contact->customer;

                    return [
                        'name' => $contact->name,
                        'email' => $contact->email,
                        'customer' => $customer?->company_name ?: $customer?->name,
                    ];
                },
            ],
        ];
    }
}
