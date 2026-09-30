<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\BusinessRuleException;
use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Order\Order;
use Closure;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class UpdateOrderMutation extends Mutation
{
    use RequiresPermissions;

    /**
     * The REST route's gate; enforced (with the token's abilities) before
     * the resolver runs. See EnforcePermissions.
     */
    protected function permissions(): array
    {
        return ['edit_orders'];
    }

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
                'rules' => ['nullable', 'string', 'max:5000'],
            ],
            'status' => [
                'type' => Type::string(),
                'description' => 'Order status',
                'rules' => ['nullable', 'string', 'in:pending,processing,shipped,delivered,cancelled'],
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Order notes',
                'rules' => ['nullable', 'string', 'max:5000'],
            ],
            'discount_type' => [
                'type' => Type::string(),
                'description' => 'Order-level discount type: percent or fixed (send null with discount_value null to remove it)',
                'rules' => ['nullable', 'string', 'in:percent,fixed'],
            ],
            'discount_value' => [
                'type' => Type::float(),
                'description' => 'Order-level discount value; totals are recomputed on the server',
                'rules' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user();
        if (!$user) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Unauthenticated');
        }
        if (!$user->hasPermission('edit_orders')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Unauthorized');
        }

        $organizationId = $user->organization_id;

        $order = Order::forOrganization($organizationId)->find($args['id']);

        if (!$order) {
            throw new Error('Order not found');
        }

        $updateData = collect($args)->except(['id', 'discount_type', 'discount_value'])->toArray();

        // Order-level discount change: recomputed under the order's row lock
        // by the same service the web and REST surfaces use.
        if (array_key_exists('discount_type', $args) || array_key_exists('discount_value', $args)) {
            try {
                $order = app(\App\Services\OrderService::class)->changeOrderDiscount(
                    $order,
                    $args['discount_type'] ?? null,
                    $args['discount_value'] ?? null,
                );
            } catch (\Illuminate\Validation\ValidationException $e) {
                throw new Error(collect($e->errors())->flatten()->first() ?? $e->getMessage());
            }
        }

        // Detect a cancel transition: the status-only change that must restock.
        // Route it through OrderService::cancel() so the web, REST, and GraphQL
        // surfaces share one locked, idempotent, guard-enforcing implementation.
        $cancelling = ($updateData['status'] ?? null) === 'cancelled'
            && $order->status !== \App\Enums\OrderStatus::CANCELLED;

        if ($cancelling) {
            unset($updateData['status']);
        }

        // An order waiting for approval cannot ship or be delivered yet.
        if (isset($updateData['status']) && $order->approvalBlocks($updateData['status'])) {
            throw new Error(Order::APPROVAL_PENDING_MESSAGE);
        }

        // Handle status change timestamps
        if (isset($updateData['status'])) {
            if ($updateData['status'] === 'shipped' && !$order->shipped_at) {
                $updateData['shipped_at'] = now();
            }
            if ($updateData['status'] === 'delivered' && !$order->delivered_at) {
                $updateData['delivered_at'] = now();
            }
        }

        if ($updateData !== []) {
            $order->update($updateData);
        }

        if ($cancelling) {
            try {
                $order = app(\App\Services\OrderService::class)->cancel($order);
            } catch (BusinessRuleException $e) {
                throw new Error($e->getMessage());
            }
        }

        $order->load('items');

        return $order;
    }
}
