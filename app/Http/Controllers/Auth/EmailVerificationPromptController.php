<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for email verification prompt.
 *
 * Handles displaying the email verification notice.
 */
class EmailVerificationPromptController extends Controller
{
    /**
     * Display the email verification prompt.
     *
     * @param Request $request The incoming HTTP request
     * @return RedirectResponse|Response
     */
    public function __invoke(Request $request): RedirectResponse|Response
    {
        return $request->user()->hasVerifiedEmail()
                    ? redirect()->intended(route('dashboard', absolute: false))
                    : Inertia::render('Auth/VerifyEmail', ['status' => session('status')]);
    }
}
