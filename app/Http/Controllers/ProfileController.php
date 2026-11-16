<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use App\Services\Organizations\OrganizationMembershipService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing user profile.
 *
 * Handles displaying, updating, and deleting the authenticated
 * user's profile information.
 */
class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * @param ProfileUpdateRequest $request The validated profile update request
     * @return RedirectResponse
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        // The email is written only when it actually changes.
        $request->user()->fill($request->safe()->only($request->changesEmail() ? ['name', 'email'] : ['name']));

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     *
     * @param Request $request The incoming HTTP request with password confirmation
     * @return RedirectResponse
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Like deleting a user from the Users screen, never leave an
        // organization (home or joined) without an administrator.
        if ($this->isLastAdministratorAnywhere($user)) {
            return Redirect::back()->withErrors([
                'password' => __('Cannot delete the last administrator.'),
            ]);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * Whether $user is the only administrator of their home organization or
     * of any other organization they belong to.
     */
    private function isLastAdministratorAnywhere(User $user): bool
    {
        $memberships = app(OrganizationMembershipService::class);

        $adminOf = array_keys(array_filter(
            $memberships->allMemberships($user),
            fn ($role): bool => $role === 'admin',
        ));

        if ($user->homeRole() === 'admin' && $user->homeOrganizationId() !== null) {
            $adminOf[] = $user->homeOrganizationId();
        }

        foreach (array_unique($adminOf) as $organizationId) {
            if ($memberships->isLastAdministrator($organizationId, $user)) {
                return true;
            }
        }

        return false;
    }
}
