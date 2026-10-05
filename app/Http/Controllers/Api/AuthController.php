<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Enums\SecurityEvent;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckApiPermission;
use App\Models\User;
use App\Services\Organizations\ActiveOrganization;
use App\Services\Organizations\OrganizationMembershipService;
use App\Services\SecurityEventLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * @tags Authentication
 */
class AuthController extends Controller
{
    /**
     * Handle API login request.
     *
     * @unauthenticated
     *
     * @param Request $request The incoming HTTP request containing credentials
     * @return JsonResponse
     * @throws ValidationException
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
            // Bind the token to another organization the user belongs to
            // (default: their home organization).
            'organization_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            // Same event the web guard fires, so API sign-in failures reach the
            // security log and failed-login alerts. Only the email is passed.
            event(new Failed('sanctum', $user, ['email' => (string) $request->email]));

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->two_factor_enabled) {
            $this->verifyTwoFactor($request, $user);
        }

        // Checked after the password (and second factor), so membership of an
        // organization is never disclosed to someone without the credentials.
        if ($request->filled('organization_id')
            && ! app(ActiveOrganization::class)->activate($user, (int) $request->input('organization_id'))) {
            throw ValidationException::withMessages([
                'organization_id' => ['You are not a member of that organization.'],
            ]);
        }

        event(new Login('sanctum', $user, false));

        $deviceName = $request->device_name ?? 'api-token';
        $token = $user->createToken($deviceName);

        return response()->json([
            'message' => 'Login successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'organization_id' => $user->organization_id,
            ],
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Verify the second factor for an API login attempt.
     *
     * Throws ValidationException so the request rolls up to the standard
     * 422 response without ever issuing a Sanctum token.
     */
    protected function verifyTwoFactor(Request $request, User $user): void
    {
        if ($request->filled('recovery_code')) {
            $matched = false;

            DB::transaction(function () use ($user, $request, &$matched) {
                $locked = $user->newQuery()->lockForUpdate()->findOrFail($user->getKey());

                $storedJson = $locked->two_factor_recovery_codes
                    ? decrypt($locked->two_factor_recovery_codes)
                    : '[]';
                $storedCodes = json_decode($storedJson, true) ?: [];

                $key = TwoFactorController::findAndConsumeRecoveryCode(
                    (string) $request->input('recovery_code'),
                    $storedCodes
                );

                if ($key === null) {
                    return; // $matched stays false
                }

                $locked->forceFill([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values($storedCodes))),
                ])->save();
                $matched = true;
            });

            if (!$matched) {
                app(SecurityEventLogger::class)->record(SecurityEvent::TWO_FACTOR_FAILED, $user, $user, ['method' => 'recovery_code', 'guard' => 'sanctum']);

                throw ValidationException::withMessages([
                    'recovery_code' => ['The provided recovery code is invalid.'],
                ]);
            }

            return;
        }

        $code = $request->input('code');
        if (!$code) {
            throw ValidationException::withMessages([
                'code' => ['Two-factor authentication code is required.'],
            ]);
        }

        $secret = decrypt($user->two_factor_secret);

        if (!(new Google2FA())->verifyKey($secret, (string) $code)) {
            app(SecurityEventLogger::class)->record(SecurityEvent::TWO_FACTOR_FAILED, $user, $user, ['method' => 'totp', 'guard' => 'sanctum']);

            throw ValidationException::withMessages([
                'code' => ['The provided two-factor code is invalid.'],
            ]);
        }
    }

    /**
     * Handle API logout request.
     *
     * @param Request $request The incoming HTTP request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        // Revoke the current access token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get the authenticated user.
     *
     * @param Request $request The incoming HTTP request
     * @return JsonResponse
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'organization_id' => $user->organization_id,
            'organization' => $user->organization ? [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
            ] : null,
            // Every organization the user belongs to; a token works in one
            // (organization above): sign in with organization_id for another.
            'organizations' => app(OrganizationMembershipService::class)->organizationsFor($user)
                ->map(fn ($organization) => ['id' => (int) $organization->id, 'name' => $organization->name])
                ->values(),
            'permissions' => $user->getAllPermissions(),
        ]);
    }

    /**
     * Create a new API token.
     *
     * @param Request $request The incoming HTTP request containing token name and abilities
     * @return JsonResponse
     */
    public function createToken(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        // Allowlist of valid token abilities: every Permission enum case and
        // every permission an active plugin registered, plus the wildcard '*'
        // which only admins may request.
        $allowedAbilities = Permission::values();
        $allowedAbilities[] = '*';

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', Rule::in($allowedAbilities)],
        ]);

        // A minted token is never broader than the token minting it, nor than
        // the user's own permissions. What the caller may grant:
        //   - unrestricted caller (session, login `*` token): admin → ['*'],
        //     non-admin → the permissions they hold
        //   - scoped caller token → its declared abilities (for a non-admin,
        //     only those they still hold)
        // Never [] : CheckApiPermission treats a token without declared
        // abilities as unrestricted (kept for tokens minted before this rule).
        $declared = CheckApiPermission::declaredAbilities($user->currentAccessToken());
        $held = $isAdmin ? null : $user->getAllPermissions();

        if ($declared === null) {
            $grantable = $isAdmin ? ['*'] : $held;
        } else {
            $grantable = $isAdmin ? $declared : array_values(array_intersect($declared, $held));
        }

        if ($request->filled('abilities')) {
            $abilities = array_values(array_unique($request->input('abilities')));

            if (in_array('*', $abilities, true) && $grantable !== ['*']) {
                throw ValidationException::withMessages([
                    'abilities' => [$isAdmin
                        ? 'A scoped token cannot issue wildcard (*) tokens.'
                        : 'Only admin users may issue wildcard (*) tokens.'],
                ]);
            }

            if ($grantable !== ['*'] && array_diff($abilities, $grantable) !== []) {
                throw ValidationException::withMessages([
                    'abilities' => ['You can only grant abilities your current token and permissions allow: '
                        .implode(', ', array_diff($abilities, $grantable)).' is not allowed.'],
                ]);
            }
        } else {
            $abilities = $grantable;
        }

        if ($abilities === []) {
            throw ValidationException::withMessages([
                'abilities' => ['You hold no permissions that could be granted to a token.'],
            ]);
        }

        $token = $user->createToken($request->name, $abilities);

        return response()->json([
            'message' => 'Token created successfully',
            'token' => $token->plainTextToken,
            'name' => $request->name,
            'abilities' => $abilities,
        ], 201);
    }

    /**
     * Revoke a specific token.
     *
     * @param Request $request The incoming HTTP request
     * @param int $tokenId The ID of the token to revoke
     * @return JsonResponse
     */
    public function revokeToken(Request $request, int $tokenId): JsonResponse
    {
        // Only tokens of the organization this token works in.
        $token = $request->user()->organizationTokens()->find($tokenId);

        if (!$token) {
            return response()->json([
                'message' => 'Token not found',
                'error' => 'not_found',
            ], 404);
        }

        $token->delete();

        return response()->json([
            'message' => 'Token revoked successfully',
        ]);
    }
}
