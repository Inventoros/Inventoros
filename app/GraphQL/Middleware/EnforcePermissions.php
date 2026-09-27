<?php

declare(strict_types=1);

namespace App\GraphQL\Middleware;

use App\Http\Middleware\CheckApiPermission;
use App\Models\User;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Rebing\GraphQL\Error\AuthorizationError;
use Rebing\GraphQL\Support\Middleware;

/**
 * Field middleware enforcing the same gate as the REST `api.permission`
 * middleware: the user must hold one of the permissions AND the acting
 * token's declared abilities must allow it.
 *
 * It runs in the field's resolver pipeline before argument validation, so an
 * unauthorized caller learns nothing from validation errors (for example
 * whether a foreign id exists).
 */
final class EnforcePermissions extends Middleware
{
    /**
     * @param  array<int, string>  $permissions  Any of these grants access.
     */
    public function __construct(private readonly array $permissions) {}

    public function handle($root, array $args, $context, ResolveInfo $info, Closure $next)
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationError('Unauthenticated');
        }

        $tokenAllows = CheckApiPermission::tokenAllows($user->currentAccessToken());

        $granted = collect($this->permissions)->contains(
            fn (string $p): bool => $user->hasPermission($p) && $tokenAllows($p)
        );

        if (! $granted) {
            throw new AuthorizationError('Unauthorized');
        }

        return $next($root, $args, $context, $info);
    }
}
