<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Illuminate\Support\Facades\DB;
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

        if (!$user->hasPermission('create_orders')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Unauthorized');
        }

        $organizationId = $user->organization_id;

        return DB::transaction(function () use ($args, $organizationId, $user) {
            $order = Order::create([
                'organization_id' => $organizationId,
                'order_number' => Order::generateOrderNumber($organizationId),
                'source' => $args['source'] ?? 'graphql',
                'external_id' => $args['external_id'] ?? null,
                'customer_name' => $args['customer_name'],
                'customer_email' => $args['customer_email'] ?? null,
                'customer_address' => $args['customer_address'] ?? null,
                'status' => $args['status'] ?? 'pending',
                'currency' => $args['currency'] ?? 'USD',
                'order_date' => $args['order_date'] ?? now(),
                'notes' => $args['notes'] ?? null,
                'created_by' => $user->id,
                'subtotal' => 0,
                'tax' => 0,
                'shipping' => 0,
                'total' => 0,
            ]);

            $subtotal = 0;
            $totalTax = 0;

            foreach ($args['items'] as $itemData) {
                // Scope to user's organization and lock for update
                $product = Product::where('id', $itemData['product_id'])
                    ->where('organization_id', $organizationId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Check stock sufficiency
                if ($product->stock < $itemData['quantity']) {
                    throw new \GraphQL\Error\Error(
                        "Insufficient stock for {$product->name}. Available: {$product->stock}, Requested: {$itemData['quantity']}"
                    );
                }

                $unitPrice = $itemData['unit_price'] ?? $product->selling_price ?? $product->price ?? 0;
                $itemSubtotal = $unitPrice * $itemData['quantity'];
                $itemTax = $itemData['tax'] ?? 0;
                $itemTotal = $itemSubtotal + $itemTax;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $unitPrice,
                    'subtotal' => $itemSubtotal,
                    'tax' => $itemTax,
                    'total' => $itemTotal,
                ]);

                $product->decrement('stock', $itemData['quantity']);

                $subtotal += $itemSubtotal;
                $totalTax += $itemTax;
            }

            $order->update([
                'subtotal' => $subtotal,
                'tax' => $totalTax,
                'total' => $subtotal + $totalTax,
            ]);

            $order->load('items');

            return $order;
        });
    }
}
