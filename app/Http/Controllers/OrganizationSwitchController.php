<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Auth\Organization;
use App\Services\Organizations\ActiveOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches the signed-in session to another organization the user belongs to
 * (the organization switcher in the top bar). The session id and CSRF token
 * are regenerated and the user lands on the dashboard: the page they were on
 * belongs to the previous organization.
 */
class OrganizationSwitchController extends Controller
{
    public function __invoke(Request $request, ActiveOrganization $organizations): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $organizations->switchTo($request, $request->user(), (int) $validated['organization_id']);
        } catch (AuthorizationException) {
            abort(403, 'You are not a member of that organization.');
        }

        $name = Organization::query()->whereKey($validated['organization_id'])->value('name');

        return redirect()->route('dashboard')->with('success', "Switched to {$name}.");
    }
}
