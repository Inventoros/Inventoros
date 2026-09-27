<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Customer;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class CustomerQuery extends Query
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'customer',
        'description' => 'Get a single customer by ID',
    ];

    protected function permissions(): array
    {
        return ['view_customers'];
    }

    public function type(): Type
    {
        return GraphQL::type('Customer');
    }

    public function args(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the customer'],
        ];
    }

    public function resolve($root, array $args)
    {
        return Customer::withCount('orders')
            ->forOrganization($this->organizationId())
            ->find($args['id']) ?? throw new Error('Customer not found');
    }
}
