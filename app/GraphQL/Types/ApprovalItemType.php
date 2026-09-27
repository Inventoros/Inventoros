<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Type as GraphQLType;

/**
 * A request in an approval workflow (purchase order, stock adjustment
 * request or stock transfer), as described by ApprovalService::describe().
 */
class ApprovalItemType extends GraphQLType
{
    protected $attributes = [
        'name' => 'ApprovalItem',
        'description' => 'A purchase order, stock adjustment request or stock transfer in an approval workflow',
    ];

    public function fields(): array
    {
        return [
            'type' => ['type' => Type::nonNull(Type::string()), 'description' => 'purchase_order, stock_adjustment or stock_transfer'],
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'Id of the purchase order, stock adjustment request or transfer'],
            'reference' => ['type' => Type::string(), 'description' => 'PO number, transfer number or request reference'],
            'title' => ['type' => Type::string(), 'description' => 'Short description of the request'],
            'summary' => ['type' => Type::string(), 'description' => 'What is being asked for'],
            'status' => ['type' => Type::string(), 'description' => 'pending, approved or rejected'],
            'amount' => ['type' => Type::float(), 'description' => 'PO total or adjustment value, when known'],
            'requester' => ['type' => Type::string(), 'description' => 'Who asked for approval'],
            'requested_at' => ['type' => Type::string(), 'description' => 'When approval was asked for (ISO 8601)'],
            'approver' => ['type' => Type::string(), 'description' => 'Who decided'],
            'approved_at' => ['type' => Type::string(), 'description' => 'When it was decided (ISO 8601)'],
            'notes' => ['type' => Type::string(), 'description' => 'Decision notes or rejection reason'],
            'url' => ['type' => Type::string(), 'description' => 'Where to see it in the web app'],
        ];
    }
}
