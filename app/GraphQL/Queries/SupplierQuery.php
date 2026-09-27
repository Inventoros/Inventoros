<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Inventory\Supplier;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class SupplierQuery extends Query
{
    use RequiresPermissions;

    /**
     * The REST route's gate; enforced (with the token's abilities) before
     * the resolver runs. See EnforcePermissions.
     */
    protected function permissions(): array
    {
        return ['view_suppliers'];
    }

    protected $attributes = [
        'name' => 'supplier',
        'description' => 'Get a single supplier by ID',
    ];

    public function type(): Type
    {
        return GraphQL::type('Supplier');
    }

    public function args(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the supplier',
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        // Read authorization: mirror the REST route's permission gate.
        // GraphQL previously enforced none, so any authenticated user could
        // read data their role is denied over REST.
        if (! auth()->user()?->hasPermission('view_suppliers')) {
            throw new AuthorizationException('Unauthorized');
        }

        $user = auth()->user();
        $organizationId = $user->organization_id;

        return Supplier::with('products')
            ->forOrganization($organizationId)
            ->findOrFail($args['id']);
    }
}
