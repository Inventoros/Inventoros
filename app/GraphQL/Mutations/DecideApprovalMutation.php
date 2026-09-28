<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\ApprovalException;
use App\GraphQL\Concerns\RequiresPermissions;
use App\Http\Middleware\CheckApiPermission;
use App\Services\ApprovalService;
use Closure;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Error\AuthorizationError;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class DecideApprovalMutation extends Mutation
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'decideApproval',
        'description' => 'Approve or reject a pending purchase order, stock adjustment request or stock transfer',
    ];

    /**
     * Any approve_* permission reaches the resolver; the one for the
     * specific type is checked there (role in ApprovalService, token here).
     */
    protected function permissions(): array
    {
        return ['approve_purchase_orders', 'approve_stock_adjustments', 'approve_stock_transfers'];
    }

    public function type(): Type
    {
        return GraphQL::type('ApprovalItem');
    }

    public function args(): array
    {
        return [
            'type' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'purchase_order, stock_adjustment or stock_transfer',
                'rules' => ['required', 'in:'.implode(',', ApprovalService::TYPES)],
            ],
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Id of the request',
                'rules' => ['required', 'integer'],
            ],
            'decision' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'approve or reject',
                'rules' => ['required', 'in:approve,reject'],
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Comment for the requester; required to reject',
                'rules' => ['nullable', 'string', 'max:1000', 'required_if:decision,reject'],
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = $this->actor();
        $approvals = app(ApprovalService::class);

        // A scoped token must carry the ability for this particular type,
        // not just any approve_* ability (same rule as the REST endpoint).
        $tokenAllows = CheckApiPermission::tokenAllows($user->currentAccessToken());
        if (! $tokenAllows(ApprovalService::permissionFor($args['type'])->value)) {
            throw new AuthorizationError('This token cannot approve this kind of request.');
        }

        try {
            $subject = $args['decision'] === 'approve'
                ? $approvals->approve($user, $args['type'], (int) $args['id'], $args['notes'] ?? null)
                : $approvals->reject($user, $args['type'], (int) $args['id'], (string) $args['notes']);
        } catch (ApprovalException $e) {
            throw new Error($e->getMessage());
        }

        return $approvals->describe($args['type'], $subject);
    }
}
