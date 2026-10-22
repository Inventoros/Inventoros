<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Services\StockBalanceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListStockBalancesTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'list_stock_balances';

    protected string $description = 'Stock by product or variant SKU, warehouse and location. Returns on-hand, reserved, available and in-transit units with warehouse permissions, search, sorting and pagination. Available quantities at different bins must not be summed when unlocated holds also constrain the SKU total.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string(), 'warehouse_id' => $schema->integer(), 'location_id' => $schema->integer(),
            'out_of_stock' => $schema->boolean(), 'include_zero' => $schema->boolean(),
            'sort_by' => $schema->string()->enum(StockBalanceService::SORTS), 'sort_dir' => $schema->string()->enum(['asc', 'desc']),
            'page' => $schema->integer(), 'per_page' => $schema->integer()->description('Maximum 100.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_products']);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'warehouse_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'],
            'out_of_stock' => ['nullable', 'boolean'], 'include_zero' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'string'], 'sort_dir' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return Response::json(app(StockBalanceService::class)->paginate($this->user(), $data)->toArray());
    }
}
