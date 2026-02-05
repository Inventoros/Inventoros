<?php

namespace App\Http\Controllers;

use App\Services\SettingsService;
use App\Mail\TestEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index()
    {
        if (!auth()->user()->isAdmin()) {
            abort(403, 'Only organization admins can access settings');
        }

        return Inertia::render('Settings/Index', [
            'emailConfig' => SettingsService::getEmailConfig(),
            'userPreferences' => auth()->user()->notification_preferences ?? [],
        ]);
    }

    public function updateEmail(Request $request)
    {
        if (!auth()->user()->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'provider' => 'required|in:smtp,phpmail,mailgun,sendgrid',
            'from_address' => 'required|email',
            'from_name' => 'required|string|max:255',

            'smtp.host' => 'required_if:provider,smtp|nullable|string',
            'smtp.port' => 'required_if:provider,smtp|nullable|integer|between:1,65535',
            'smtp.username' => 'nullable|string',
            'smtp.password' => 'nullable|string',
            'smtp.encryption' => 'required_if:provider,smtp|nullable|in:tls,ssl,none',

            'mailgun.domain' => 'required_if:provider,mailgun|nullable|string',
            'mailgun.secret' => 'required_if:provider,mailgun|nullable|string',

            'sendgrid.api_key' => 'required_if:provider,sendgrid|nullable|string',
        ]);

        // Save general settings
        SettingsService::set('email.provider', $validated['provider']);
        SettingsService::set('email.from_address', $validated['from_address']);
        SettingsService::set('email.from_name', $validated['from_name']);

        // Save provider-specific settings
        if ($validated['provider'] === 'smtp') {
            SettingsService::set('email.smtp.host', $validated['smtp']['host']);
            SettingsService::set('email.smtp.port', $validated['smtp']['port']);
            SettingsService::set('email.smtp.username', $validated['smtp']['username']);
            SettingsService::set('email.smtp.password', $validated['smtp']['password'], true);
            SettingsService::set('email.smtp.encryption', $validated['smtp']['encryption']);
        } elseif ($validated['provider'] === 'mailgun') {
            SettingsService::set('email.mailgun.domain', $validated['mailgun']['domain']);
            SettingsService::set('email.mailgun.secret', $validated['mailgun']['secret'], true);
        } elseif ($validated['provider'] === 'sendgrid') {
            SettingsService::set('email.sendgrid.api_key', $validated['sendgrid']['api_key'], true);
        }

        return back()->with('success', 'Email settings saved successfully');
    }

    public function testEmail(Request $request)
    {
        if (!auth()->user()->isAdmin()) {
            abort(403);
        }

        $request->validate([
            'test_email' => 'required|email'
        ]);

        try {
            SettingsService::applyEmailConfig();

            Mail::to($request->test_email)->send(new TestEmail([
                'organization' => auth()->user()->organization->name,
                'tested_by' => auth()->user()->name,
            ]));

            return back()->with('success', 'Test email sent successfully! Check your inbox.');

        } catch (\Exception $e) {
            \Log::error('Test email failed', [
                'error' => $e->getMessage(),
                'organization_id' => auth()->user()->organization_id,
            ]);

            return back()->with('error', 'Failed to send test email: ' . $e->getMessage());
        }
    }
}
