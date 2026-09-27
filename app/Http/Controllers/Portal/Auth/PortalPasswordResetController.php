<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auth\Organization;
use App\Models\CustomerContact;
use App\Support\Portal\PortalContext;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Forgotten-password flow for portal contacts, on its own broker
 * (`customer_contacts`) and token table, scoped to the portal's
 * organization. The response to a reset request is the same whether or not
 * the email is a contact, so the form cannot be used to discover contacts.
 */
class PortalPasswordResetController extends Controller
{
    private const GENERIC_STATUS = 'If that email belongs to a portal account, we have emailed a password reset link.';

    public function request(): Response
    {
        return Inertia::render('Portal/Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function email(Request $request): RedirectResponse
    {
        $organization = PortalContext::organization($request);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        // The broker's own per-contact throttle may refuse a repeat request;
        // the answer to the browser is the same either way.
        $this->broker()->sendResetLink($this->credentials($organization, $validated['email']));

        return back()->with('status', self::GENERIC_STATUS);
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('Portal/Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = PortalContext::organization($request);

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = $this->broker()->reset(
            $this->credentials($organization, $validated['email']) + [
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (CustomerContact $contact, string $password) {
                $contact->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [trans($status)],
            ]);
        }

        return redirect()
            ->route('portal.login', ['organization' => $organization->slug])
            ->with('status', 'Your password has been reset. You can sign in now.');
    }

    private function broker(): PasswordBroker
    {
        return Password::broker('customer_contacts');
    }

    /**
     * Only an active contact (password set, not revoked) of this
     * organization can request or complete a reset. An invited contact who
     * never set a password uses their invitation instead.
     *
     * @return array<int|string, mixed>
     */
    private function credentials(Organization $organization, string $email): array
    {
        return [
            'organization_id' => $organization->id,
            'email' => mb_strtolower(trim($email)),
            fn (Builder $query) => $query->whereNull('revoked_at')->whereNotNull('password'),
        ];
    }
}
