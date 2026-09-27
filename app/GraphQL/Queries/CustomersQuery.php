<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Customer;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class CustomersQuery extends Query
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'customers',
        'description' => 'List customers with optional filters',
    ];

    protected function permissions(): array
    {
        return ['view_customers'];
    }

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('Customer'));
    }

    public function args(): array
    {
        return [
            'search' => ['type' => Type::string(), 'description' => 'Search by name, code, email, company or contact'],
            'is_active' => ['type' => Type::boolean(), 'description' => 'Filter by active status'],
            'limit' => ['type' => Type::int(), 'description' => 'Maximum number of results (default: 50, max: 100)'],
        ];
    }

    public function resolve($root, array $args)
    {
        return Customer::withCount('orders')
            ->forOrganization($this->organizationId())
            ->when($args['search'] ?? null, fn ($query, $search) => $query->search($search))
            ->when(isset($args['is_active']), fn ($query) => $query->where('is_active', $args['is_active']))
            ->orderBy('name')
            ->limit(max(1, min($args['limit'] ?? 50, 100)))
            ->get();
    }
}
