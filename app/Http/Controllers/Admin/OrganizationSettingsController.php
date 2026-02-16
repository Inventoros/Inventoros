<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing organization settings.
 *
 * Handles organization general settings, regional settings,
 * and user management within the organization.
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

    /**
     * Display user management for the organization.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function users(Request $request): Response
    {
        $user = $request->user();
        $organization = Organization::with(['users'])->find($user->organization_id);

        return Inertia::render('Settings/Organization/Users', [
            'organization' => $organization,
            'user' => $user,
        ]);
    }

    /**
     * Store a new user in the organization.
     *
     * @param Request $request The incoming HTTP request containing user data
     * @return RedirectResponse
     */
    public function storeUser(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Ensure only admins can create users
        if (!$user->is_admin) {
            abort(403, 'Only administrators can create users.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'is_admin' => 'boolean',
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'organization_id' => $user->organization_id,
            'role' => $validated['is_admin'] ?? false ? 'admin' : 'member',
        ]);

        return redirect()->back()->with('success', 'User created successfully.');
    }

    /**
     * Update an existing user.
     *
     * @param Request $request The incoming HTTP request containing updated user data
     * @param User $user The user to update
     * @return RedirectResponse
     */
    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $currentUser = $request->user();

        // Ensure only admins can update users
        if (!$currentUser->is_admin) {
            abort(403, 'Only administrators can update users.');
        }

        // Ensure the user belongs to the same organization
        if ($user->organization_id !== $currentUser->organization_id) {
            abort(403, 'You can only update users in your organization.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'is_admin' => 'boolean',
        ]);

        // Don't allow removing admin from the last admin
        if (isset($validated['is_admin']) && !$validated['is_admin']) {
            $adminCount = User::where('organization_id', $currentUser->organization_id)
                ->where('role', 'admin')
                ->count();

            if ($adminCount <= 1 && $user->role === 'admin') {
                return redirect()->back()->withErrors(['is_admin' => 'Cannot remove admin role from the last administrator.']);
            }
        }

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['is_admin'] ?? false ? 'admin' : 'member',
        ]);

        return redirect()->back()->with('success', 'User updated successfully.');
    }

    /**
     * Delete a user from the organization.
     *
     * @param Request $request The incoming HTTP request
     * @param User $user The user to delete
     * @return RedirectResponse
     */
    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        $currentUser = $request->user();

        // Ensure only admins can delete users
        if (!$currentUser->is_admin) {
            abort(403, 'Only administrators can delete users.');
        }

        // Ensure the user belongs to the same organization
        if ($user->organization_id !== $currentUser->organization_id) {
            abort(403, 'You can only delete users in your organization.');
        }

        // Don't allow deleting yourself
        if ($user->id === $currentUser->id) {
            return redirect()->back()->withErrors(['user' => 'You cannot delete your own account.']);
        }

        // Don't allow deleting the last admin
        if ($user->role === 'admin') {
            $adminCount = User::where('organization_id', $currentUser->organization_id)
                ->where('role', 'admin')
                ->count();

            if ($adminCount <= 1) {
                return redirect()->back()->withErrors(['user' => 'Cannot delete the last administrator.']);
            }
        }

        $user->delete();

        return redirect()->back()->with('success', 'User deleted successfully.');
    }
}
