<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Settings > API tokens: the signed-in user's personal access tokens.
 *
 * A token can only be granted abilities the user currently holds. The API
 * enforces the user's permissions and the token's abilities together, so a
 * token is a subset of its owner and never more. The plaintext is flashed
 * exactly once after creation and is never stored or sent again.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Tokens of the active organization: a token works in the
        // organization it was created in, so each one lists its own.
        $tokens = $user->organizationTokens()
            ->latest()
            ->latest('id')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Settings/ApiTokens/Index', [
            'tokens' => $tokens,
            'availableAbilities' => $this->grantableAbilities($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $held = collect($this->grantableAbilities($request))->flatten(1)->pluck('value')->all();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'distinct', Rule::in($held)],
        ], [
            'abilities.*.in' => 'You can only grant permissions you hold.',
        ]);

        $token = $request->user()->createToken($validated['name'], array_values($validated['abilities']));

        return redirect()->route('settings.api-tokens.index')
            ->with('success', 'API token created. Copy it now, it will not be shown again.')
            ->with('newApiToken', $token->plainTextToken);
    }

    public function destroy(Request $request, int $tokenId): RedirectResponse
    {
        $token = $request->user()->organizationTokens()->whereKey($tokenId)->first();

        abort_if($token === null, 404);

        $token->delete();

        return redirect()->route('settings.api-tokens.index')
            ->with('success', 'API token revoked.');
    }

    /**
     * The permissions the user holds, grouped by category for the picker.
     *
     * @return array<string, array<int, array{value: string, label: string, description: string}>>
     */
    private function grantableAbilities(Request $request): array
    {
        $held = $request->user()->getAllPermissions();

        return collect(Permission::grouped())
            ->map(fn (array $permissions) => array_values(array_filter(
                $permissions,
                fn (array $permission) => in_array($permission['value'], $held, true),
            )))
            ->filter(fn (array $permissions) => $permissions !== [])
            ->all();
    }
}
