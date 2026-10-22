<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Services\OrderFulfilmentPlanner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class PlanOrderFulfilmentTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'plan_order_fulfilment';

    protected string $description = 'Compare accessible warehouses for a draft order without reserving or changing stock. Shows coverage, incoming transfers and suggested bin transfers. Items require product_id, optional product_variant_id and positive integer quantity. At most 100 lines and 10 warehouse candidates.';

    public function schema(JsonSchema $schema): array
    {
        return ['items' => $schema->array()->description('Order lines: product_id, product_variant_id (required for variant products), quantity.')->required()];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_products', 'create_orders']);
        $data = $request->validate(OrderFulfilmentPlanner::rules());

        return Response::json(app(OrderFulfilmentPlanner::class)->plan($this->user(), $data['items']));
    }
}
