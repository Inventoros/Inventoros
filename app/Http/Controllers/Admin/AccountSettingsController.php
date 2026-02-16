<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing user account settings.
 *
 * Handles profile updates, password changes, notification
 * preferences, and user preferences.
 */
class AccountSettingsController extends Controller
{
    /**
     * Display the account settings page.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Account/Index', [
            'user' => $user,
        ]);
    }

    /**
     * Update user profile information.
     *
     * @param Request $request The incoming HTTP request containing profile data
     * @return RedirectResponse
     */
    public function updateProfile(Request $request): RedirectResponse
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
     *
     * @param Request $request The incoming HTTP request containing password data
     * @return RedirectResponse
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        // Verify current password
        if (!Hash::check($validated['current_password'], $user->password)) {
            return redirect()->back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return redirect()->back()->with('success', 'Password updated successfully.');
    }

    /**
     * Update notification preferences.
     *
     * @param Request $request The incoming HTTP request containing notification preferences
     * @return RedirectResponse
     */
    public function updateNotifications(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'email_notifications' => 'boolean',
            'low_stock_alerts' => 'boolean',
            'order_notifications' => 'boolean',
            'system_notifications' => 'boolean',
        ]);

        // Store notification preferences
        $preferences = $user->notification_preferences ?? [];
        $user->notification_preferences = array_merge($preferences, $validated);
        $user->save();

        return redirect()->back()->with('success', 'Notification preferences updated successfully.');
    }

    /**
     * Update user preferences.
     *
     * @param Request $request The incoming HTTP request containing user preferences
     * @return RedirectResponse
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'theme' => 'nullable|in:light,dark,auto',
            'language' => 'nullable|string|max:10',
            'items_per_page' => 'nullable|integer|min:10|max:100',
        ]);

        // Store user preferences nested within notification_preferences
        $preferences = $user->notification_preferences ?? [];
        $user->notification_preferences = array_merge($preferences, ['preferences' => $validated]);
        $user->save();

        return redirect()->back()->with('success', 'Preferences updated successfully.');
    }
}
