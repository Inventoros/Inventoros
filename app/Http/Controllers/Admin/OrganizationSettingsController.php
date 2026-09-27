<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\Organization;
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

        return Inertia::render('Settings/Organization/Index', [
            'organization' => $organization,
            'user' => $user,
        ]);
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

        // Ensure only admins can update organization settings
        if (!$user->is_admin) {
            abort(403, 'Only administrators can update organization settings.');
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

        // Ensure only admins can update organization settings
        if (!$user->is_admin) {
            abort(403, 'Only administrators can update organization settings.');
        }

        $validated = $request->validate([
            'currency' => 'required|string|max:3',
            'timezone' => 'required|string|max:255',
            'date_format' => 'nullable|string|max:50',
            'time_format' => 'nullable|string|max:50',
        ]);

        $organization = Organization::find($user->organization_id);
        $organization->update($validated);

        return redirect()->back()->with('success', 'Regional settings updated successfully.');
    }
}
