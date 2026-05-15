<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
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
        if (! $this->user()->hasAnyPermission($permissions)) {
            throw new AuthorizationException(
                'Token lacks any of the required permissions: '.implode(', ', $permissions)
            );
        }
    }
}
