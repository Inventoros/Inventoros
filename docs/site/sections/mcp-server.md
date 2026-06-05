Inventoros ships with a built-in Model Context Protocol (MCP) server so AI clients like Claude Desktop, Claude Code, Cursor, and custom agents can read inventory state and act on it on a user's behalf. The server is built on the official `laravel/mcp` package.

Learn more about MCP: https://modelcontextprotocol.io/

### Endpoint at a glance

- URL: `POST {your-host}/mcp`
- Transport: streamable HTTP (per the 2025-11-25 MCP spec)
- Auth: Sanctum bearer token (same as REST)
- Rate limit: 60 requests per minute per user (shared with REST)

`GET` and `DELETE` against `/mcp` return `405 Method Not Allowed` by design; only `POST` is supported.

### Why MCP?

MCP is the open standard that lets AI assistants discover and invoke tools on your behalf, like a USB port for AI applications. The Inventoros MCP server exposes the most useful inventory operations as tools that Claude (or any compatible client) can call without copy-pasting from screen to screen:

- "What's running low in the warehouse?" calls `list_low_stock`.
- "Adjust SKU WIDGET-001 by -3, dropped pallet." calls `adjust_stock` after asking you to confirm.
- "Draft a PO with our usual quantities." calls `list_low_stock`, groups by supplier, and asks before `create_purchase_order`.

### Security model

Every MCP request authenticates with the same Sanctum bearer token used by the REST API. The token's user provides:

- Organization scope. Every tool and resource is filtered by `organization_id`. Cross-organization access surfaces as a not-found error, never `403`, so the existence of other organizations' data is never leaked.
- Permission set. Every tool calls the same `hasAnyPermission()` check the REST controllers use.

Destructive tools carry the standard MCP `IsDestructive` annotation so well-behaved clients prompt for confirmation before invoking. `send_purchase_order` is also `IsIdempotent`. Requests without a valid token return HTTP 401. Lacking the right permission returns an MCP error response with `isError: true`.

### Quick start: Claude Desktop / Claude Code

1. Get a token from your Inventoros install:

```bash
curl -X POST "${APP_URL}/api/v1/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"you@example.com","password":"...","device_name":"Claude Desktop"}'
```

Copy the `token` value from the response.

2. Add Inventoros to your client's MCP config. For Claude Desktop on macOS, edit `~/Library/Application Support/Claude/claude_desktop_config.json` (Claude Code uses `~/.claude/mcp.json`):

```json
{
  "mcpServers": {
    "inventoros": {
      "type": "http",
      "url": "https://inventoros.example.com/mcp",
      "headers": {
        "Authorization": "Bearer 1|paste-your-token-here"
      }
    }
  }
}
```

3. Restart the client. The Inventoros tools appear in the tool picker.
4. Try `who_am_i` first to confirm the token round-trips.

### Quick start: Cursor

Add an entry to `~/.cursor/mcp.json` (or the workspace-level `.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "inventoros": {
      "url": "https://inventoros.example.com/mcp",
      "headers": {
        "Authorization": "Bearer 1|paste-your-token-here"
      }
    }
  }
}
```

### Tool catalog

22 tools across 7 surfaces. Tools marked destructive mutate state and should be confirmed before invocation.

Identity:

- `who_am_i` (no permission required). Identify the authenticated user, organization, role, and the permissions this token holds. Run first to confirm wiring.

Catalog (read):

- `list_products` (`view_products` or `manage_products`). Paginated product list with search, category, warehouse, and low-stock filters.
- `search_products` (`view_products` or `manage_products`). Lightweight substring search returning up to 25 matches.
- `get_product` (`view_products` or `manage_products`). Single product with category, location, suppliers, options, and active variants.
- `lookup_barcode` (`view_products` or `manage_products`). Exact match on barcode/SKU across products and variants.
- `list_categories` (`view_categories` or `view_products`). Category list.
- `list_locations` (`view_locations` or `view_products`). Storage locations, optionally filtered by warehouse.
- `list_warehouses` (`view_warehouses` or `view_products`). Warehouse list.

Stock:

- `list_low_stock` (`view_products` or `manage_products`). Products at or below `min_stock`, sorted by shortage.
- `adjust_stock` (`manage_stock`). Apply a signed delta with a reason. Destructive; confirm first.

Sales:

- `list_orders` (`view_orders` or `manage_orders`). Paginated orders with status, source, warehouse, and date filters.
- `get_order` (`view_orders` or `manage_orders`). Single order with line items.
- `create_order` (`manage_orders`). Create an order; decrements stock; fails if any line is short. Destructive.

Purchasing:

- `list_suppliers` (`view_suppliers` or `manage_suppliers`). Supplier list.
- `list_purchase_orders` (`view_purchase_orders` or `manage_purchase_orders`). Paginated POs with status and supplier filters.
- `get_purchase_order` (`view_purchase_orders` or `manage_purchase_orders`). Single PO with supplier and line items.
- `create_purchase_order` (`manage_purchase_orders`). Create a draft PO. Does not affect stock until received. Destructive.
- `send_purchase_order` (`edit_purchase_orders` or `manage_purchase_orders`). Transition draft to sent. Destructive and idempotent.
- `receive_purchase_order` (`receive_purchase_orders` or `manage_purchase_orders`). Receive items; writes stock; transitions to partial or received. Destructive.

Manufacturing:

- `list_work_orders` (`manage_stock`). Paginated work orders with a status filter.
- `start_work_order` (`manage_stock`). Validate component stock and transition to in_progress. Destructive.

Catalog (write):

- `create_product` (`manage_products`). Create a product. Destructive.

### Resources

Browsable read-only data the agent can fetch by URI:

- `inventoros://low-stock`. Snapshot of every product at or below its `min_stock`.
- `inventoros://orders/recent`. The 25 most recent sales orders with status and totals.

### Prompts

Canned templates the agent can invoke:

- `reorder_helper` (optional `warehouse_id` argument). Walks the user through low-stock review, supplier grouping, draft PO creation, and (after explicit confirmation) marking POs sent.

### Error model

Errors are returned as MCP `isError: true` responses with a human-readable string:

- Unauthenticated: HTTP 401, before any tool runs.
- Forbidden: "Token lacks any of the required permissions: ...".
- Not found: "Product not found in this organization." Cross-org access surfaces here, not as 403.
- Validation: comma-joined Laravel validation messages.
- Domain: for example "Cannot remove 100 units; only 5 on hand."

### Extending

Tools live in `app/Mcp/Tools/` in the Inventoros codebase. To add one:

1. Generate the class: `php artisan make:mcp-tool MyNewTool`.
2. Use the `App\Mcp\Concerns\AuthenticatesMcpRequest` trait for `user()`, `organizationId()`, and `authorize([...])`.
3. Define `description`, `schema(JsonSchema $schema)`, and `handle(Request $request)`.
4. Annotate destructive tools with `#[IsDestructive]`.
5. Register the class in `app/Mcp/Servers/InventorosServer.php`'s `$tools` array.
6. Add a test in `tests/Feature/Mcp/InventorosMcpServerTest.php`.

### Versioning

The MCP server follows the same release cycle as Inventoros core. Tools may be added in minor releases; signature-breaking changes wait for a major version bump.
