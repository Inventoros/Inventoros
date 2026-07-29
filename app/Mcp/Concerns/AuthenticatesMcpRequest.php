<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Http\Middleware\CheckApiPermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

trait AuthenticatesMcpRequest
{
    protected function user(): User
    {
        $user = Auth::guard('sanctum')->user() ?? request()?->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('MCP requests require an authenticated Sanctum token.');
        }

        return $user;
    }

    protected function organizationId(): int
    {
        $orgId = $this->user()->organization_id;

        if ($orgId === null) {
            throw new AuthorizationException('Authenticated user is not attached to an organization.');
        }

        return (int) $orgId;
    }

    /**
     * @param  array<int, string>  $permissions  Any of these grants access.
     */
    protected function authorize(array $permissions): void
    {
        $user = $this->user();

        // Enforce the acting token's declared abilities as well as the user's
        // role permissions, so a scoped ("read-only") token can't drive a
        // destructive MCP tool. Unrestricted for session tokens, no declared
        // scope, or a `*` token (see CheckApiPermission::tokenAllows).
        $tokenAllows = CheckApiPermission::tokenAllows($user->currentAccessToken());

        $granted = collect($permissions)->contains(
            fn (string $p): bool => $user->hasPermission($p) && $tokenAllows($p)
        );

        if (! $granted) {
            throw new AuthorizationException(
                'Token lacks any of the required permissions: '.implode(', ', $permissions)
            );
        }
    }
}
