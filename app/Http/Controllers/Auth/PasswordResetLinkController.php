<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for password reset link requests.
 *
 * Handles displaying the forgot password form and sending
 * password reset links.
 */
class PasswordResetLinkController extends Controller
{
    /**
     * The one answer to every reset request, so the response never reveals
     * whether an email belongs to an account (mirrors the customer portal).
     */
    public const GENERIC_STATUS = 'If that email belongs to an account, we have emailed a password reset link.';

    /**
     * Display the password reset link request view.
     *
     * @return Response
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @param Request $request The incoming HTTP request containing email
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Unknown emails and the broker's own per-account throttle (which only
        // refuses real accounts) get the same answer as a sent link, so the
        // response cannot be used to find out which emails have accounts.
        Password::sendResetLink(
            $request->only('email')
        );

        return back()->with('status', __(self::GENERIC_STATUS));
    }
}
