<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Services\ApprovalService;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class PendingApprovalsQuery extends Query
{
    protected $attributes = [
        'name' => 'pendingApprovals',
        'description' => 'Requests waiting for the current user to approve or reject',
    ];

    public function type(): Type
    {
        return Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('ApprovalItem'))));
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user() ?? throw new AuthorizationException('Unauthorized');

        return app(ApprovalService::class)->pendingFor($user)->all();
    }
}
