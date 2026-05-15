<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\ReorderHelperPrompt;
use App\Mcp\Resources\LowStockResource;
use App\Mcp\Resources\RecentOrdersResource;
use App\Mcp\Tools\AdjustStockTool;
use App\Mcp\Tools\CreateOrderTool;
use App\Mcp\Tools\CreateProductTool;
use App\Mcp\Tools\CreatePurchaseOrderTool;
use App\Mcp\Tools\GetOrderTool;
use App\Mcp\Tools\GetProductTool;
use App\Mcp\Tools\GetPurchaseOrderTool;
use App\Mcp\Tools\ListCategoriesTool;
use App\Mcp\Tools\ListLocationsTool;
use App\Mcp\Tools\ListLowStockTool;
use App\Mcp\Tools\ListOrdersTool;
use App\Mcp\Tools\ListProductsTool;
use App\Mcp\Tools\ListPurchaseOrdersTool;
use App\Mcp\Tools\ListSuppliersTool;
use App\Mcp\Tools\ListWarehousesTool;
use App\Mcp\Tools\ListWorkOrdersTool;
use App\Mcp\Tools\LookupBarcodeTool;
use App\Mcp\Tools\ReceivePurchaseOrderTool;
use App\Mcp\Tools\SearchProductsTool;
use App\Mcp\Tools\SendPurchaseOrderTool;
use App\Mcp\Tools\StartWorkOrderTool;
use App\Mcp\Tools\WhoAmITool;
use Laravel\Mcp\Server;

class InventorosServer extends Server
{
    protected string $name = 'Inventoros MCP';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        # Inventoros MCP Server

        This server lets AI agents read and act on inventory data scoped to a single
        Inventoros organization. Every request authenticates with a Sanctum bearer
        token; the agent inherits the user's permissions and organization scope.

        ## Capabilities

        - **Read** products, low-stock items, orders, suppliers, purchase orders,
          warehouses, locations, categories, and work orders.
        - **Write** stock adjustments, new products, new orders, and new purchase
          orders.
        - **Transition** purchase orders (send, receive) and work orders (start).

        ## Conventions

        - Quantities are integers. Money values are decimal strings.
        - Every list tool accepts `per_page` (max 100) and `page` for pagination.
        - Tools that mutate state include a `confirm` style description that
          encourages the AI client to confirm before invocation.
        - Errors return `isError: true` with a human-readable message.

        Start with `whoami` if you want to inspect the active token's identity and
        permissions.
        MARKDOWN;

    /** @var array<int, class-string> */
    protected array $tools = [
        WhoAmITool::class,

        // Catalog reads
        ListProductsTool::class,
        SearchProductsTool::class,
        GetProductTool::class,
        LookupBarcodeTool::class,
        ListCategoriesTool::class,
        ListLocationsTool::class,
        ListWarehousesTool::class,

        // Stock
        ListLowStockTool::class,
        AdjustStockTool::class,

        // Sales
        ListOrdersTool::class,
        GetOrderTool::class,
        CreateOrderTool::class,

        // Purchasing
        ListSuppliersTool::class,
        ListPurchaseOrdersTool::class,
        GetPurchaseOrderTool::class,
        CreatePurchaseOrderTool::class,
        SendPurchaseOrderTool::class,
        ReceivePurchaseOrderTool::class,

        // Manufacturing
        ListWorkOrdersTool::class,
        StartWorkOrderTool::class,

        // Catalog writes
        CreateProductTool::class,
    ];

    /** @var array<int, class-string> */
    protected array $resources = [
        LowStockResource::class,
        RecentOrdersResource::class,
    ];

    /** @var array<int, class-string> */
    protected array $prompts = [
        ReorderHelperPrompt::class,
    ];
}
