<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\InputType;

class PurchaseOrderReceiveItemInputType extends InputType
{
    protected $attributes = [
        'name' => 'PurchaseOrderReceiveItemInput',
        'description' => 'A quantity received against a purchase order line',
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The purchase order line ID',
            ],
            'quantity_to_receive' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Quantity received now',
                'rules' => ['required', 'integer', 'min:0'],
            ],
            'receiving_location_id' => [
                'type' => Type::int(),
                'description' => 'Optional bin for this receipt only. Null keeps the existing destination; planned receipts stay in their planned warehouse.',
                'rules' => ['nullable', 'integer', 'min:1'],
            ],
        ];
    }
}
