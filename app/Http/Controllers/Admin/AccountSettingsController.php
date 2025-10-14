<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AccountSettingsController extends Controller
{
    /**
     * Display the account settings page.
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
     */
    public function updateNotifications(Request $request)
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
     */
    public function updatePreferences(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'theme' => 'nullable|in:light,dark,auto',
            'language' => 'nullable|string|max:10',
            'items_per_page' => 'nullable|integer|min:10|max:100',
        ]);

        // Store user preferences
        $preferences = $user->notification_preferences ?? [];
        $user->notification_preferences = array_merge($preferences, ['preferences' => $validated]);
        $user->save();

        return redirect()->back()->with('success', 'Preferences updated successfully.');
    }
}
