<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Auth\Organization;
use App\Support\ApprovalSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing organization settings.
 *
 * Handles organization general and regional settings. Users are managed by
 * UserController (/users).
 */
class OrganizationSettingsController extends Controller
{
    /**
     * Display the organization settings page.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = Organization::with(['users'])->find($user->organization_id);
        $canManage = $user->hasPermission(Permission::MANAGE_ORGANIZATION);

        return Inertia::render('Settings/Organization/Index', [
            'organization' => $organization,
            'user' => $user,
            'approvalSettings' => ApprovalSettings::forOrganization($organization)->toArray(),
            // Explicit capability flags. The page must not rely on
            // `user.is_admin`: it is an accessor that is never serialized, so
            // the page used to render read-only even for administrators.
            'can' => [
                'manageOrganization' => $canManage,
                'viewUsers' => $user->hasPermission(Permission::VIEW_USERS),
            ],
            'canManageOrganization' => $canManage,
            'portal' => [
                'enabled' => (bool) $organization?->portal_enabled,
                'login_url' => $organization ? route('portal.login', ['organization' => $organization->slug]) : null,
            ],
            // Regional settings are picked from these lists.
            'currencies' => collect(self::currencyCodes($organization?->currency))
                ->map(fn (string $code) => ['code' => $code, 'name' => config("currencies.supported.{$code}.name") ?? $code])
                ->values()
                ->all(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * The currencies an organization can use: the supported list, plus the
     * one it already has (an older install may hold a code outside it).
     *
     * @return array<int, string>
     */
    private static function currencyCodes(?string $current): array
    {
        $codes = array_keys((array) config('currencies.supported', []));
        $current = strtoupper(trim((string) $current));

        if ($current !== '' && ! in_array($current, $codes, true)) {
            $codes[] = $current;
        }

        return $codes;
    }

    /**
     * Turn the customer portal on or off for the organization.
     *
     * @param Request $request The incoming HTTP request
     * @return RedirectResponse
     */
    public function updatePortal(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPermission(Permission::MANAGE_ORGANIZATION)) {
            abort(403, 'You do not have permission to update organization settings.');
        }

        $validated = $request->validate([
            'portal_enabled' => 'required|boolean',
        ]);

        $organization = Organization::find($user->organization_id);
        $organization->forceFill(['portal_enabled' => (bool) $validated['portal_enabled']])->save();

        return redirect()->back()->with(
            'success',
            $organization->portal_enabled ? 'Customer portal turned on.' : 'Customer portal turned off.'
        );
    }

    /**
     * Update the organization's approval workflow settings.
     *
     * Every workflow is off by default; a blank threshold means every
     * request of that kind needs approval once the workflow is on.
     */
    public function updateApprovals(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'purchase_orders_enabled' => 'boolean',
            'purchase_orders_threshold' => 'nullable|numeric|min:0|max:999999999',
            'stock_adjustments_enabled' => 'boolean',
            'stock_adjustments_quantity_threshold' => 'nullable|integer|min:0|max:999999999',
            'stock_adjustments_value_threshold' => 'nullable|numeric|min:0|max:999999999',
            'stock_transfers_enabled' => 'boolean',
            'admins_can_self_approve' => 'boolean',
            'orders_enabled' => 'boolean',
        ]);

        $organization = Organization::findOrFail($request->user()->organization_id);

        $approvals = ApprovalSettings::fromArray(array_merge(
            ApprovalSettings::forOrganization($organization)->toArray(),
            $validated,
        ));

        $settings = $organization->settings ?? [];
        $settings[ApprovalSettings::KEY] = $approvals->toArray();
        $organization->update(['settings' => $settings]);

        return redirect()->back()->with('success', 'Approval settings saved.');
    }

    /**
     * Update organization general settings.
     *
     * @param Request $request The incoming HTTP request containing organization data
     * @return RedirectResponse
     */
    public function updateGeneral(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPermission(Permission::MANAGE_ORGANIZATION)) {
            abort(403, 'You do not have permission to update organization settings.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:255',
        ]);

        $organization = Organization::find($user->organization_id);
        $organization->update($validated);

        return redirect()->back()->with('success', 'Organization settings updated successfully.');
    }

    /**
     * Update organization regional settings.
     *
     * @param Request $request The incoming HTTP request containing regional settings
     * @return RedirectResponse
     */
    public function updateRegional(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPermission(Permission::MANAGE_ORGANIZATION)) {
            abort(403, 'You do not have permission to update organization settings.');
        }

        $validated = $request->validate([
            'currency' => ['required', 'string', \Illuminate\Validation\Rule::in(self::currencyCodes($user->organization?->currency))],
            'timezone' => ['required', 'string', 'timezone:all'],
            'date_format' => 'nullable|string|max:50',
            'time_format' => 'nullable|string|max:50',
        ]);

        $organization = Organization::find($user->organization_id);
        $organization->update($validated);

        return redirect()->back()->with('success', 'Regional settings updated successfully.');
    }
}
