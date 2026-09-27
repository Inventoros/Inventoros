<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\TestEmail;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing application settings.
 *
 * Handles organization-wide settings including email configuration
 * and user notification preferences.
 */
class SettingsController extends Controller
{
    /**
     * Stored provider secrets, as [config section, key]. They are never sent
     * to the browser; the page gets a "<key>_set" flag instead, and a blank
     * value on save keeps the stored one.
     */
    private const SECRET_FIELDS = [
        ['smtp', 'password'],
        ['mailgun', 'secret'],
        ['sendgrid', 'api_key'],
    ];

    /**
     * Display the settings hub, which links to every settings page. The page
     * hides the sections the user has no permission for.
     */
    public function hub(): Response
    {
        return Inertia::render('Settings/Index');
    }

    /**
     * Display the email (SMTP/provider) and notification preferences page.
     */
    public function email(): Response
    {
        $this->authorizeEmailSettings();

        $emailConfig = SettingsService::getEmailConfig();

        foreach (self::SECRET_FIELDS as [$section, $key]) {
            $emailConfig[$section][$key.'_set'] = filled($emailConfig[$section][$key] ?? null);
            $emailConfig[$section][$key] = '';
        }

        return Inertia::render('Settings/Email', [
            'emailConfig' => $emailConfig,
            'userPreferences' => auth()->user()->notification_preferences ?? [],
        ]);
    }

    /**
     * Update email configuration settings.
     *
     * @param Request $request The incoming HTTP request containing email settings
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateEmail(Request $request)
    {
        $this->authorizeEmailSettings();

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
            SettingsService::set('email.smtp.username', $validated['smtp']['username'] ?? null);
            $this->setSecret('email.smtp.password', $validated['smtp']['password'] ?? null);
            SettingsService::set('email.smtp.encryption', $validated['smtp']['encryption']);
        } elseif ($validated['provider'] === 'mailgun') {
            SettingsService::set('email.mailgun.domain', $validated['mailgun']['domain']);
            $this->setSecret('email.mailgun.secret', $validated['mailgun']['secret'] ?? null);
        } elseif ($validated['provider'] === 'sendgrid') {
            $this->setSecret('email.sendgrid.api_key', $validated['sendgrid']['api_key'] ?? null);
        }

        return back()->with('success', 'Email settings saved successfully');
    }

    /**
     * Send a test email to verify email configuration.
     *
     * The page calls this with axios, which follows redirects, so a JSON
     * caller gets JSON: 200 with a message on success, 422 with the error
     * message on failure. Other callers get the redirect with a flash.
     *
     * @param Request $request The incoming HTTP request containing test email address
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function testEmail(Request $request)
    {
        $this->authorizeEmailSettings();

        $request->validate([
            'test_email' => 'required|email'
        ]);

        try {
            SettingsService::applyEmailConfig();

            Mail::to($request->test_email)->send(new TestEmail([
                'organization' => auth()->user()->organization->name,
                'tested_by' => auth()->user()->name,
            ]));

            $message = 'Test email sent successfully! Check your inbox.';

            return $request->expectsJson()
                ? response()->json(['message' => $message])
                : back()->with('success', $message);

        } catch (\Exception $e) {
            \Log::error('Test email failed', [
                'error' => $e->getMessage(),
                'organization_id' => auth()->user()->organization_id,
            ]);

            $message = 'Failed to send test email: ' . $e->getMessage();

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : back()->with('error', $message);
        }
    }

    /**
     * Store an encrypted provider secret, keeping the stored value when the
     * form left the field blank (the page never receives the stored secret,
     * so blank means "unchanged").
     */
    private function setSecret(string $key, ?string $value): void
    {
        if (blank($value)) {
            return;
        }

        SettingsService::set($key, $value, true);
    }

    /**
     * Email settings are gated on manage_organization, the same permission
     * the routes and the settings hub use (admins have every permission).
     */
    private function authorizeEmailSettings(): void
    {
        if (! auth()->user()->hasPermission('manage_organization')) {
            abort(403, 'You do not have permission to manage email settings.');
        }
    }
}
