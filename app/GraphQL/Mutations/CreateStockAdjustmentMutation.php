<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Services\ApprovalService;
use App\Services\WarehouseAccessService;
use Closure;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class CreateStockAdjustmentMutation extends Mutation
{
    protected $attributes = [
        'name' => 'createStockAdjustment',
        'description' => 'Create a stock adjustment for a product',
    ];

    public function type(): Type
    {
        return GraphQL::type('StockAdjustment');
    }

    public function args(): array
    {
        return [
            'product_id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The product ID to adjust',
                'rules' => ['required', 'integer', 'exists:products,id'],
            ],
            'quantity' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Adjustment quantity (positive or negative)',
                'rules' => ['required', 'integer'],
            ],
            'type' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Adjustment type: manual, count, damage, return, transfer',
                'rules' => ['required', 'string', 'in:manual,count,damage,return,transfer'],
            ],
            'reason' => [
                'type' => Type::string(),
                'description' => 'Reason for adjustment',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Additional notes',
                'rules' => ['nullable', 'string', 'max:5000'],
            ],
            'location_id' => [
                'type' => Type::int(),
                'description' => 'Location bin to apply the adjustment to. Required for users restricted to assigned warehouses.',
                'rules' => ['nullable', 'integer'],
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user();
        if (!$user->hasPermission('manage_stock')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Unauthorized');
        }

        $organizationId = $user->organization_id;

        $product = Product::where('id', $args['product_id'])
            ->where('organization_id', $organizationId)
            ->first();

        if (!$product) {
            throw new Error('Product not found');
        }

        $locationId = $args['location_id'] ?? null;

        if ($locationId !== null && ! ProductLocation::where('id', $locationId)->where('organization_id', $organizationId)->exists()) {
            throw new Error('Location not found');
        }

        // A restricted user may only adjust a bin in one of their warehouses.
        app(WarehouseAccessService::class)->authorizeLocation($user, $locationId);

        // An adjustment the organization's approval rules cover cannot be
        // applied directly; it has to go through an approver.
        if (app(ApprovalService::class)->stockAdjustmentNeedsApproval($product, null, (int) $args['quantity'])) {
            throw new Error('This adjustment needs approval. Submit it with requestStockAdjustmentApproval.');
        }

        $adjustment = StockAdjustment::adjust(
            $product,
            $args['quantity'],
            $args['type'],
            $args['reason'] ?? null,
            $args['notes'] ?? null,
            locationId: $locationId,
        );

        $adjustment->load(['product', 'user']);

        return $adjustment;
    }
}
