<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Inventory\Product;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class ProductQuery extends Query
{
    use RequiresPermissions;

    /**
     * The REST route's gate; enforced (with the token's abilities) before
     * the resolver runs. See EnforcePermissions.
     */
    protected function permissions(): array
    {
        return ['view_products'];
    }

    protected $attributes = [
        'name' => 'product',
        'description' => 'Get a single product by ID',
    ];

    public function type(): Type
    {
        return GraphQL::type('Product');
    }

    public function args(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the product',
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        // Read authorization: mirror the REST route's permission gate.
        // GraphQL previously enforced none, so any authenticated user could
        // read data their role is denied over REST.
        if (! auth()->user()?->hasPermission('view_products')) {
            throw new AuthorizationException('Unauthorized');
        }

        $user = auth()->user();
        $organizationId = $user->organization_id;

        return Product::with(['category', 'location', 'variants', 'suppliers'])
            ->forOrganization($organizationId)
            ->findOrFail($args['id']);
    }
}
