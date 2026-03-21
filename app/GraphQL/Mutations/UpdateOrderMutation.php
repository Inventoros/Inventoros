<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Order\Order;
use Closure;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class UpdateOrderMutation extends Mutation
{
    protected $attributes = [
        'name' => 'updateOrder',
        'description' => 'Update an existing order',
    ];

    public function type(): Type
    {
        return GraphQL::type('Order');
    }

    public function args(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the order to update',
                'rules' => ['required', 'integer'],
            ],
            'customer_name' => [
                'type' => Type::string(),
                'description' => 'Customer name',
                'rules' => ['sometimes', 'string', 'max:255'],
            ],
            'customer_email' => [
                'type' => Type::string(),
                'description' => 'Customer email',
                'rules' => ['nullable', 'email', 'max:255'],
            ],
            'customer_address' => [
                'type' => Type::string(),
                'description' => 'Customer address',
                'rules' => ['nullable', 'string'],
            ],
            'status' => [
                'type' => Type::string(),
                'description' => 'Order status',
                'rules' => ['nullable', 'string', 'in:pending,processing,shipped,delivered,cancelled'],
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Order notes',
                'rules' => ['nullable', 'string'],
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user();
        $organizationId = $user->organization_id;

        $order = Order::forOrganization($organizationId)->find($args['id']);

        if (!$order) {
            throw new Error('Order not found');
        }

        $updateData = collect($args)->except(['id'])->toArray();

        // Handle status change timestamps
        if (isset($updateData['status'])) {
            if ($updateData['status'] === 'shipped' && !$order->shipped_at) {
                $updateData['shipped_at'] = now();
            }
            if ($updateData['status'] === 'delivered' && !$order->delivered_at) {
                $updateData['delivered_at'] = now();
            }
        }

        $order->update($updateData);
        $order->load('items');

        return $order;
    }
}
