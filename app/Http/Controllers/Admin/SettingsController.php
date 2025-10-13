<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Display the settings page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = Organization::with(['users'])->find($user->organization_id);

        return Inertia::render('Settings/Index', [
            'organization' => $organization,
            'user' => $user,
        ]);
    }

    /**
     * Update organization settings.
     */
    public function updateOrganization(Request $request)
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
            'currency' => 'nullable|string|max:3',
            'timezone' => 'nullable|string|max:255',
        ]);

        $organization = Organization::find($user->organization_id);
        $organization->update($validated);

        return redirect()->back()->with('success', 'Organization settings updated successfully.');
    }

    /**
     * Update user profile.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
        ]);

        $user->update($validated);

        return redirect()->back()->with('success', 'Profile updated successfully.');
    }

    /**
     * Update user password.
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Verify current password
        if (!password_verify($validated['current_password'], $user->password)) {
            return redirect()->back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $user->update([
            'password' => bcrypt($validated['password']),
        ]);

        return redirect()->back()->with('success', 'Password updated successfully.');
    }

    /**
     * Update notification preferences.
     */
    public function updateNotifications(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'email_notifications' => 'boolean',
            'low_stock_alerts' => 'boolean',
            'order_notifications' => 'boolean',
        ]);

        // Store notification preferences in user settings
        // This assumes you have a JSON column or separate settings table
        $user->settings = array_merge($user->settings ?? [], $validated);
        $user->save();

        return redirect()->back()->with('success', 'Notification preferences updated successfully.');
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
            'is_admin' => $validated['is_admin'] ?? false,
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
                ->where('is_admin', true)
                ->count();

            if ($adminCount <= 1 && $user->is_admin) {
                return redirect()->back()->withErrors(['is_admin' => 'Cannot remove admin role from the last administrator.']);
            }
        }

        $user->update($validated);

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
        if ($user->is_admin) {
            $adminCount = \App\Models\User::where('organization_id', $currentUser->organization_id)
                ->where('is_admin', true)
                ->count();

            if ($adminCount <= 1) {
                return redirect()->back()->withErrors(['user' => 'Cannot delete the last administrator.']);
            }
        }

        $user->delete();

        return redirect()->back()->with('success', 'User deleted successfully.');
    }
}
