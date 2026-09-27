<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Customer;
use GraphQL\Type\Definition\Type;
use Illuminate\Validation\Rule;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class CreateCustomerMutation extends Mutation
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'createCustomer',
        'description' => 'Create a customer',
    ];

    protected function permissions(): array
    {
        return ['create_customers'];
    }

    public function type(): Type
    {
        return GraphQL::type('Customer');
    }

    public function args(): array
    {
        return CustomerArgs::args(requireName: true);
    }

    protected function rules(array $args = []): array
    {
        return CustomerArgs::rules(requireName: true, uniqueCode: Rule::unique('customers', 'code')->where('organization_id', $this->organizationId()));
    }

    public function resolve($root, array $args)
    {
        $args['organization_id'] = $this->organizationId();
        $args['is_active'] = $args['is_active'] ?? true;

        return Customer::create($args);
    }
}
