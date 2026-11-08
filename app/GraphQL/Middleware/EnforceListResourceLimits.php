<?php

declare(strict_types=1);

namespace App\GraphQL\Middleware;

use App\GraphQL\Support\ListQueryComplexity;
use App\GraphQL\Support\ListResourceLimits;
use Closure;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use Rebing\GraphQL\Support\ExecutionMiddleware\AbstractExecutionMiddleware;
use Rebing\GraphQL\Support\OperationParams;

final class EnforceListResourceLimits extends AbstractExecutionMiddleware
{
    public function handle(string $schemaName, Schema $schema, OperationParams $params, $rootValue, $contextValue, Closure $next): ExecutionResult
    {
        $rule = new ListQueryComplexity(ListResourceLimits::MAX_RESPONSE_NODES);
        $rule->setRawVariableValues(is_array($params->variables) ? $params->variables : []);
        DocumentValidator::addRule($rule);

        try {
            return $next($schemaName, $schema, $params, $rootValue, $contextValue);
        } finally {
            DocumentValidator::removeRule($rule);
        }
    }
}
