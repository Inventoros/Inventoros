<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\InsufficientStockException;
use App\Services\OrderService;
use Closure;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class CreateOrderMutation extends Mutation
{
    protected $attributes = [
        'name' => 'createOrder',
        'description' => 'Create a new order',
    ];

    public function type(): Type
    {
        return GraphQL::type('Order');
    }

    public function args(): array
    {
        return [
            'customer_name' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Customer name',
                'rules' => ['required', 'string', 'max:255'],
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
            'source' => [
                'type' => Type::string(),
                'description' => 'Order source',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'external_id' => [
                'type' => Type::string(),
                'description' => 'External system ID',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'status' => [
                'type' => Type::string(),
                'description' => 'Order status: pending, processing, shipped, delivered, cancelled',
                'rules' => ['nullable', 'string', 'in:pending,processing,shipped,delivered,cancelled'],
            ],
            'currency' => [
                'type' => Type::string(),
                'description' => 'Currency code',
                'rules' => ['nullable', 'string', 'max:3'],
            ],
            'order_date' => [
                'type' => Type::string(),
                'description' => 'Order date (YYYY-MM-DD)',
                'rules' => ['nullable', 'date'],
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Order notes',
                'rules' => ['nullable', 'string'],
            ],
            'items' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('OrderItemInput')))),
                'description' => 'Order line items',
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user();

        if (! $user->hasPermission('create_orders')) {
            throw new AuthorizationException('Unauthorized');
        }

        $args['status'] ??= 'pending';
        $args['order_date'] ??= now();
        $args['currency'] ??= 'USD';

        try {
            // OrderService owns the create invariant (lock → validate → ledger
            // + decrement, wrapped in SequenceNumberRetry) shared with the
            // web/REST/MCP surfaces.
            $order = app(OrderService::class)->create($args, $user, $args['source'] ?? 'graphql');
        } catch (InsufficientStockException $e) {
            throw new Error($e->getMessage());
        }

        return $order->load('items');
    }
}
