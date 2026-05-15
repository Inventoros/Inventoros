<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use Illuminate\Auth\AuthenticationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class WhoAmITool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Identify the authenticated user, organization, and the permissions this token holds. Run this first to confirm the token is wired up correctly.';

    public function handle(Request $request): Response
    {
        try {
            $user = $this->user();
        } catch (AuthenticationException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'organization_id' => $user->organization_id,
            'is_admin' => (bool) $user->is_admin,
            'is_manager' => (bool) $user->is_manager,
        ]);
    }
}
