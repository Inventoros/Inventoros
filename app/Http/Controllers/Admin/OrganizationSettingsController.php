<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationSettingsController extends Controller
{
    /**
     * Display the organization settings page.
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
     */
    public function updateGeneral(Request $request)
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
     */
    public function updateRegional(Request $request)
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
     */
    public function storeUser(Request $request)
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

        $newUser = \App\Models\User::create([
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
     */
    public function updateUser(Request $request, \App\Models\User $user)
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
            $adminCount = \App\Models\User::where('organization_id', $currentUser->organization_id)
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
     */
    public function destroyUser(Request $request, \App\Models\User $user)
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
            $adminCount = \App\Models\User::where('organization_id', $currentUser->organization_id)
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
