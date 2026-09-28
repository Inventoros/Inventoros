<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Auth;

use App\Enums\SecurityEvent;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Portal\AuthenticatePortalContact;
use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Scopes\OrganizationScope;
use App\Services\SecurityEventLogger;
use App\Support\Portal\PortalContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign-in and sign-out for customer portal contacts, on the `customer` guard.
 *
 * Sign-in is scoped to the portal's organization: the same email can be a
 * contact at several organizations, and each portal only accepts its own.
 * Attempts are throttled per organization + email + IP.
 */
class PortalLoginController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public function __construct(private readonly SecurityEventLogger $security) {}

    public function create(Request $request): Response
    {
        return Inertia::render('Portal/Auth/Login', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = PortalContext::organization($request);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $email = mb_strtolower(trim($credentials['email']));
        $throttleKey = $this->throttleKey($organization, $email, (string) $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ]);
        }

        $authenticated = Auth::guard('customer')->attempt([
            'organization_id' => $organization->id,
            'email' => $email,
            'password' => $credentials['password'],
            fn (Builder $query) => $this->eligible($query, $organization),
        ]);

        if (! $authenticated) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            $this->recordFailure($organization, $email);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        /** @var CustomerContact $contact */
        $contact = Auth::guard('customer')->user();
        AuthenticatePortalContact::rememberPassword($request, $contact);
        self::recordLogin($this->security, $contact);

        return redirect()->to($this->intended($request, $organization));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $organization = PortalContext::organization($request);

        // Only the portal guard is signed out. The session itself is kept,
        // so a staff session in the same browser is not ended with it.
        Auth::guard('customer')->logout();
        $request->session()->forget(AuthenticatePortalContact::INTENDED_KEY);
        $request->session()->regenerateToken();

        return redirect()->route('portal.login', ['organization' => $organization->slug]);
    }

    /**
     * Stamp the sign-in and write it to the organization's security log.
     */
    public static function recordLogin(SecurityEventLogger $security, CustomerContact $contact, string $via = 'password'): void
    {
        $contact->forceFill(['last_login_at' => now()])->save();

        $security->record(
            SecurityEvent::PORTAL_LOGIN,
            $contact,
            null,
            ['customer_id' => $contact->customer_id, 'email' => $contact->email, 'via' => $via],
            "Customer portal sign-in by {$contact->name}",
        );
    }

    /**
     * Only contacts that have set a password, are not revoked, and belong to
     * an active, non-deleted customer of this organization can sign in.
     */
    private function eligible(Builder $query, Organization $organization): void
    {
        $query->whereNull('revoked_at')
            ->whereNotNull('password')
            ->whereIn('customer_id', Customer::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)
                ->where('is_active', true)
                ->select('id'));
    }

    private function recordFailure(Organization $organization, string $email): void
    {
        $contact = CustomerContact::where('organization_id', $organization->id)
            ->where('email', $email)
            ->first();

        // Failures for an email that is no contact here are not tied to any
        // record; like staff sign-ins they are not written to the tenant log.
        if ($contact === null) {
            return;
        }

        $this->security->record(
            SecurityEvent::PORTAL_LOGIN_FAILED,
            $contact,
            null,
            ['customer_id' => $contact->customer_id, 'email' => $email],
        );
    }

    /**
     * The portal page the contact was sent away from, if it belongs to this
     * portal; otherwise the dashboard. Never an off-portal URL.
     */
    private function intended(Request $request, Organization $organization): string
    {
        $dashboard = route('portal.dashboard', ['organization' => $organization->slug]);
        $intended = $request->session()->pull(AuthenticatePortalContact::INTENDED_KEY);

        if (is_string($intended) && ($intended === $dashboard || str_starts_with($intended, $dashboard.'/'))) {
            return $intended;
        }

        return $dashboard;
    }

    private function throttleKey(Organization $organization, string $email, string $ip): string
    {
        return 'portal-login|'.$organization->id.'|'.Str::transliterate($email).'|'.$ip;
    }
}
