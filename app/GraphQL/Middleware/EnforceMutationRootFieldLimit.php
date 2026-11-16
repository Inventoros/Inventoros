<?php

declare(strict_types=1);

namespace App\GraphQL\Middleware;

use App\GraphQL\Support\MutationRootFieldLimit;
use Closure;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use Rebing\GraphQL\Support\ExecutionMiddleware\AbstractExecutionMiddleware;
use Rebing\GraphQL\Support\OperationParams;

/**
 * Adds the mutation root-field cap (graphql.security.mutation_max_root_fields)
 * to document validation for the duration of one operation.
 */
final class EnforceMutationRootFieldLimit extends AbstractExecutionMiddleware
{
    public function handle(string $schemaName, Schema $schema, OperationParams $params, $rootValue, $contextValue, Closure $next): ExecutionResult
    {
        $rule = new MutationRootFieldLimit(max(1, (int) config('graphql.security.mutation_max_root_fields', 10)));
        DocumentValidator::addRule($rule);

        try {
            return $next($schemaName, $schema, $params, $rootValue, $contextValue);
        } finally {
            DocumentValidator::removeRule($rule);
        }
    }
}
