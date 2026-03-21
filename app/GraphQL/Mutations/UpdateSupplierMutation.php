<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Inventory\Supplier;
use Closure;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class UpdateSupplierMutation extends Mutation
{
    protected $attributes = [
        'name' => 'updateSupplier',
        'description' => 'Update an existing supplier',
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
                'description' => 'The ID of the supplier to update',
                'rules' => ['required', 'integer'],
            ],
            'name' => [
                'type' => Type::string(),
                'description' => 'Supplier name',
                'rules' => ['sometimes', 'string', 'max:255'],
            ],
            'code' => [
                'type' => Type::string(),
                'description' => 'Supplier code',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'contact_name' => [
                'type' => Type::string(),
                'description' => 'Contact person name',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'email' => [
                'type' => Type::string(),
                'description' => 'Email address',
                'rules' => ['nullable', 'email', 'max:255'],
            ],
            'phone' => [
                'type' => Type::string(),
                'description' => 'Phone number',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'address' => [
                'type' => Type::string(),
                'description' => 'Street address',
                'rules' => ['nullable', 'string'],
            ],
            'city' => [
                'type' => Type::string(),
                'description' => 'City',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'state' => [
                'type' => Type::string(),
                'description' => 'State/Province',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'zip_code' => [
                'type' => Type::string(),
                'description' => 'Zip/Postal code',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'country' => [
                'type' => Type::string(),
                'description' => 'Country',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'website' => [
                'type' => Type::string(),
                'description' => 'Website URL',
                'rules' => ['nullable', 'url', 'max:255'],
            ],
            'payment_terms' => [
                'type' => Type::string(),
                'description' => 'Payment terms',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'currency' => [
                'type' => Type::string(),
                'description' => 'Default currency',
                'rules' => ['nullable', 'string', 'max:3'],
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Additional notes',
                'rules' => ['nullable', 'string'],
            ],
            'is_active' => [
                'type' => Type::boolean(),
                'description' => 'Whether the supplier is active',
                'rules' => ['nullable', 'boolean'],
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user();
        $organizationId = $user->organization_id;

        $supplier = Supplier::forOrganization($organizationId)->find($args['id']);

        if (!$supplier) {
            throw new Error('Supplier not found');
        }

        $updateData = collect($args)->except(['id'])->toArray();
        $supplier->update($updateData);

        return $supplier;
    }
}
