<?php

declare(strict_types=1);

namespace App\GraphQL\Concerns;

use App\GraphQL\Middleware\EnforcePermissions;
use App\Models\User;
use GraphQL\Error\Error;
use Illuminate\Validation\ValidationException;
use Rebing\GraphQL\Error\ValidationError;

/**
 * Gate a query or mutation like the matching REST route: the resolver runs
 * only when the user holds one of `permissions()` and the token allows it.
 */
trait RequiresPermissions
{
    /**
     * Permissions of which the caller needs any one.
     *
     * @return array<int, string>
     */
    abstract protected function permissions(): array;

    /**
     * @return array<int, mixed>
     */
    protected function getMiddleware(): array
    {
        return array_merge([new EnforcePermissions($this->permissions())], $this->middleware);
    }

    protected function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    protected function organizationId(): int
    {
        return (int) $this->actor()->organization_id;
    }

    /**
     * Run a domain-service call, surfacing its refusals as GraphQL errors
     * rather than internal server errors.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    protected function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            throw new ValidationError('validation', $e->validator);
        } catch (\RuntimeException $e) {
            throw new Error($e->getMessage());
        }
    }
}
