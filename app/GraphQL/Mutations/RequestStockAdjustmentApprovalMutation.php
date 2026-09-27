<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\InsufficientStockException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Services\ApprovalService;
use Closure;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

/**
 * Hold a stock adjustment for approval. Stock does not change until an
 * approver approves it (decideApproval).
 */
class RequestStockAdjustmentApprovalMutation extends Mutation
{
    protected $attributes = [
        'name' => 'requestStockAdjustmentApproval',
        'description' => 'Submit a stock adjustment for approval; stock changes only once it is approved',
    ];

    public function type(): Type
    {
        return GraphQL::type('ApprovalItem');
    }

    public function args(): array
    {
        return [
            'product_id' => ['type' => Type::nonNull(Type::int()), 'rules' => ['required', 'integer']],
            'product_variant_id' => ['type' => Type::int(), 'rules' => ['nullable', 'integer']],
            'quantity' => ['type' => Type::nonNull(Type::int()), 'description' => 'Signed change', 'rules' => ['required', 'integer', 'not_in:0']],
            'type' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Adjustment type: manual, count, damage, return, transfer',
                'rules' => ['required', 'string', 'in:manual,count,damage,return,transfer'],
            ],
            'reason' => ['type' => Type::string(), 'rules' => ['nullable', 'string', 'max:255']],
            'notes' => ['type' => Type::string(), 'rules' => ['nullable', 'string', 'max:5000']],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user();
        if (! $user?->hasPermission('manage_stock')) {
            throw new AuthorizationException('Unauthorized');
        }

        $product = Product::where('organization_id', $user->organization_id)->find($args['product_id'])
            ?? throw new Error('Product not found');

        $variant = null;
        if (! empty($args['product_variant_id'])) {
            $variant = ProductVariant::where('product_id', $product->id)->find($args['product_variant_id'])
                ?? throw new Error('Variant not found');
        }

        $approvals = app(ApprovalService::class);

        try {
            $request = $approvals->requestStockAdjustment(
                $user, $product, $variant, (int) $args['quantity'], $args['type'], $args['reason'] ?? null, $args['notes'] ?? null,
            );
        } catch (InsufficientStockException $e) {
            throw new Error($e->getMessage());
        }

        return $approvals->describe(ApprovalService::STOCK_ADJUSTMENT, $request->load(['product', 'variant', 'requester']));
    }
}
