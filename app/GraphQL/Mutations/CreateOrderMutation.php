<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\GraphQL\Concerns\RequiresPermissions;
use App\Services\OrderService;
use Closure;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class CreateOrderMutation extends Mutation
{
    use RequiresPermissions;

    /**
     * The REST route's gate; enforced (with the token's abilities) before
     * the resolver runs. See EnforcePermissions.
     */
    protected function permissions(): array
    {
        return ['create_orders'];
    }

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
                'description' => 'Currency code (ISO 4217); defaults to the organization currency',
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
            'discount_type' => [
                'type' => Type::string(),
                'description' => 'Order-level discount type: percent or fixed',
                'rules' => ['nullable', 'string', 'in:percent,fixed'],
            ],
            'discount_value' => [
                'type' => Type::float(),
                'description' => 'Order-level discount: a percentage (0-100) or an amount, applied to the merchandise after line discounts and before tax',
                'rules' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
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
        // No currency: OrderService uses the organization's currency.

        try {
            // OrderService owns the create invariant (lock → validate → ledger
            // + decrement, wrapped in SequenceNumberRetry) shared with the
            // web/REST/MCP surfaces.
            $order = app(OrderService::class)->create($args, $user, $args['source'] ?? 'graphql');
        } catch (InsufficientStockException|InvalidOrderItemException $e) {
            throw new Error($e->getMessage());
        } catch (ValidationException $e) {
            // Discount problems found while pricing the order.
            throw new Error(collect($e->errors())->flatten()->first() ?? $e->getMessage());
        }

        return $order->load('items');
    }
}
