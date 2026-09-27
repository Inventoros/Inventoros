<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Customer;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\Type;
use Illuminate\Validation\Rule;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class UpdateCustomerMutation extends Mutation
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'updateCustomer',
        'description' => 'Update a customer. Only the fields passed are changed.',
    ];

    protected function permissions(): array
    {
        return ['edit_customers'];
    }

    public function type(): Type
    {
        return GraphQL::type('Customer');
    }

    public function args(): array
    {
        return ['id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the customer']]
            + CustomerArgs::args(requireName: false);
    }

    protected function rules(array $args = []): array
    {
        return CustomerArgs::rules(
            requireName: false,
            uniqueCode: Rule::unique('customers', 'code')->where('organization_id', $this->organizationId())->ignore($args['id'] ?? null),
        );
    }

    public function resolve($root, array $args)
    {
        $customer = Customer::forOrganization($this->organizationId())->find($args['id'])
            ?? throw new Error('Customer not found');

        $customer->update(collect($args)->except('id')->all());

        return $customer;
    }
}
