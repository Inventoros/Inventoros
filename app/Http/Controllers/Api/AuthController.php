<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * API Controller for authentication.
 *
 * Handles API token-based authentication including login,
 * logout, token management, and user info retrieval.
 */
class AuthController extends Controller
{
    /**
     * Handle API login request.
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
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

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
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string'],
        ]);

        $abilities = $request->abilities ?? ['*'];
        $token = $request->user()->createToken($request->name, $abilities);

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
        $token = $request->user()->tokens()->find($tokenId);

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
