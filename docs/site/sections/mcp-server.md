Inventoros ships with a built-in Model Context Protocol (MCP) server so AI clients like Claude Desktop, Claude Code, Cursor, and custom agents can read inventory state and act on it on a user's behalf. The server is built on the official `laravel/mcp` package.

Learn more about MCP: https://modelcontextprotocol.io/

## Endpoint at a glance

- URL: `POST {your-host}/mcp`
- Transport: streamable HTTP (per the 2025-11-25 MCP spec; clients on protocol versions 2025-06-18 and 2025-03-26 are also accepted)
- Auth: Sanctum bearer token (same as REST)
- Rate limit: 60 requests per minute per user (shared with REST)

`GET` and `DELETE` against `/mcp` return `405 Method Not Allowed` by design; only `POST` is supported.

## Why MCP?

MCP is the open standard that lets AI assistants discover and invoke tools on your behalf, like a USB port for AI applications. The Inventoros MCP server exposes the most useful inventory operations as tools that Claude (or any compatible client) can call without copy-pasting from screen to screen:

- "What's running low in the warehouse?" calls `list_low_stock`.
- "Adjust SKU WIDGET-001 by -3, dropped pallet." calls `adjust_stock` after asking you to confirm.
- "Draft a PO with our usual quantities." calls `list_low_stock`, groups by supplier, and asks before `create_purchase_order`.

## Security model

Every MCP request authenticates with the same Sanctum bearer token used by the REST API. The token's user provides:

- Organization scope. Every tool and resource is filtered by `organization_id`. Cross-organization access surfaces as a not-found error, never `403`, so the existence of other organizations' data is never leaked.
- Permission set. Every tool checks the user's role permissions the same way the REST API does.
- Token abilities. A token created with a limited set of abilities in Settings > API tokens can only use the tools whose permission it carries, so a read-only token cannot drive a destructive tool.

Destructive tools carry the standard MCP `IsDestructive` annotation so well-behaved clients prompt for confirmation before invoking. Read tools carry `IsReadOnly`. Requests without a valid token return HTTP 401. Lacking the right permission returns an MCP error response with `isError: true`.

## Quick start

### Get a token

Create a token in Settings > API tokens, or from the REST API:

```bash
curl -X POST "${APP_URL}/api/v1/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"you@example.com","password":"...","device_name":"MCP client"}'
```

If the account has two-factor authentication on, also send `code` (or a `recovery_code`). Copy the `token` value from the response.

### Claude Code

Add the server from a terminal:

```bash
claude mcp add --transport http inventoros https://inventoros.example.com/mcp \
  --header "Authorization: Bearer 1|paste-your-token-here"
```

Add `--scope user` to use it in every project. Run `claude mcp list` to check the connection, then try `who_am_i`.

### Claude Desktop

Claude Desktop's config file (`claude_desktop_config.json`, opened from Settings > Developer > Edit Config) starts local servers, so bridge to the remote endpoint with `mcp-remote` (needs Node.js):

```json
{
  "mcpServers": {
    "inventoros": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://inventoros.example.com/mcp",
        "--header",
        "Authorization:${INVENTOROS_AUTH}"
      ],
      "env": {
        "INVENTOROS_AUTH": "Bearer 1|paste-your-token-here"
      }
    }
  }
}
```

Restart Claude Desktop. The Inventoros tools appear in the tool picker. Try `who_am_i` first to confirm the token round-trips.

### Cursor

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

## Tools

### Tool catalog

32 tools across 8 surfaces. Tools marked destructive mutate state and should be confirmed before invocation.

Identity:

- `who_am_i` (no permission required). Identify the authenticated user, organization, role, and the permissions this token holds. Run first to confirm wiring.

Catalog (read):

- `list_products` (`view_products`). Paginated product list with search, category, warehouse, and low-stock filters.
- `search_products` (`view_products`). Lightweight substring search on name, SKU or barcode returning 10 matches by default (up to 25).
- `get_product` (`view_products`). Single product with category, location, suppliers, options, and active variants.
- `lookup_barcode` (`view_products`). Resolve a scanned code the same way the in-app scanner does: a product or variant by exact barcode, SKU or UPC, a product QR link, or a storage location by its QR code or location code.
- `list_categories` (`manage_categories` or `view_products`). Category list.
- `list_locations` (`manage_locations` or `view_products`). Storage locations, optionally filtered by warehouse.
- `list_warehouses` (`view_warehouses` or `view_products`). Warehouse list.
- `list_stock_balances` (`view_products`). Stock by SKU, variant, warehouse and location, including reserved, available and in-transit quantities, sorting and pagination.

Stock:

- `list_low_stock` (`view_products`). Products at or below `min_stock`, sorted by shortage.
- `adjust_stock` (`manage_stock`). Apply a signed delta with a reason. When the organization requires approval for the adjustment, nothing changes yet: the result is `pending_approval` until an approver decides. Destructive; confirm first.

Sales:

- `list_orders` (`view_orders`). Paginated orders with status, source, warehouse, and date filters.
- `get_order` (`view_orders`). Single order with line items.
- `create_order` (`create_orders`). Create an order; decrements stock; fails if any line is short. Accepts optional line and order discounts (applied before tax). Destructive.
- `email_order_invoice` (`edit_orders`). Email the order's invoice PDF to the customer, or to `to`, with optional CC and message. Destructive.
- `record_payment` (`record_payments`). Record a payment against an order. Partial payments are fine; a payment above the balance due needs `allow_overpayment`; cancelled orders are refused. Destructive.
- `list_shipments` (`view_shipments`). Paginated shipments with carrier, tracking and status; filter by order or status.
- `create_shipment` (`create_shipments`). Record a shipment with manual tracking, optionally marking it shipped. Destructive.

Purchasing:

- `list_suppliers` (`view_suppliers`). Supplier list.
- `list_purchase_orders` (`view_purchase_orders`). Paginated POs with status and supplier filters.
- `get_purchase_order` (`view_purchase_orders`). Single PO with supplier and line items.
- `create_purchase_order` (`create_purchase_orders`). Create a draft PO. Does not affect stock until received. Destructive.
- `send_purchase_order` (`edit_purchase_orders`). Email the PO to the supplier with the PO PDF attached (or to `to`) and mark a draft as sent. A PO that is already sent or partly received can be re-sent; its status is unchanged. Destructive.
- `receive_purchase_order` (`receive_purchase_orders`). Receive items; writes stock; transitions to partial or received. Destructive.
- `submit_purchase_order_for_approval` (`edit_purchase_orders`). Put a draft PO in front of the approvers when the organization requires approval. Approvers are notified, and the PO cannot be edited or sent until one of them decides.

Approvals:

- `list_pending_approvals` (no single permission; returns only the purchase orders, stock adjustment requests, stock transfers and sales orders the caller may decide). Each item carries the `type` and `id` to pass to `decide_approval`.
- `decide_approval` (`approve_purchase_orders`, `approve_stock_adjustments`, `approve_stock_transfers` or `approve_orders`, by `type`). Approve or reject a pending request; rejecting needs notes. Destructive.

Manufacturing:

- `list_work_orders` (`manage_stock`). Paginated work orders with a status filter.
- `start_work_order` (`manage_stock`). Move a draft or pending work order to in_progress after checking component stock, including available serials or batch quantity for tracked components. Destructive.
- `transition_stock_transfer` (`transfer_stock`). Prepare, ship, receive or cancel an unshipped warehouse transfer. Destructive.
- `delete_work_order` (`manage_stock`). Delete a draft or cancelled work order. Destructive.

Catalog (write):

- `create_product` (`create_products`). Create a product. Destructive.

## Resources and prompts

### Resources

Browsable read-only data the agent can fetch by URI:

- `inventoros://low-stock` (`view_products`). Snapshot of up to 200 products at or below their `min_stock`, largest shortage first.
- `inventoros://orders/recent` (`view_orders`). The 25 most recent sales orders with status and totals.

### Prompts

Canned templates the agent can invoke:

- `reorder_helper` (optional `warehouse_id` argument). Walks the user through low-stock review, supplier grouping, draft PO creation, and (after explicit confirmation) marking POs sent.

## Error model

Errors are returned as MCP `isError: true` responses with a human-readable string:

- Unauthenticated: HTTP 401, before any tool runs.
- Forbidden: "Token lacks any of the required permissions: ...".
- Not found: "Product not found in this organization." Cross-org access surfaces here, not as 403.
- Validation: comma-joined Laravel validation messages.
- Domain: for example "Cannot remove 100 units; only 5 on hand."

A call to a tool name that does not exist, or an unexpected server error, comes back as a JSON-RPC error instead (`-32602` and `-32603` respectively).

## Extending

Tools live in `app/Mcp/Tools/` in the Inventoros codebase. To add one:

1. Generate the class: `php artisan make:mcp-tool MyNewTool`.
2. Use the `App\Mcp\Concerns\AuthenticatesMcpRequest` trait for `user()`, `organizationId()`, and `authorize([...])`.
3. Give it an explicit snake_case `$name`, and define `description`, `schema(JsonSchema $schema)`, and `handle(Request $request)`.
4. Annotate destructive tools with `#[IsDestructive]` and read tools with `#[IsReadOnly]`.
5. Register the class in `app/Mcp/Servers/InventorosServer.php`'s `$tools` array.
6. Add a test in `tests/Feature/Mcp/InventorosMcpServerTest.php`, and list the tool in this catalog and in `docs/mcp/README.md` (`McpToolNamesMatchDocsTest` fails until both match the registered tools).

### Plugin tools

Active plugins can add tools with `register_mcp_tool($slug, $tool, $permission)` (see [Plugin Development](plugins)). Their names start with the plugin slug in snake case (for example `stock_insights_summary` from the `stock-insights` plugin), so they never replace a core tool. A plugin tool appears in `tools/list` and can be called only when the user holds the permission the plugin attached to it and the token's abilities allow it.

## Tool names in v2.0.0

Before v2.0.0 the tools were exposed under kebab-case class names such as `list-orders-tool`. They now carry the snake_case names listed above. Update any client configuration or allow-list that names tools; the full mapping is in `UPGRADE.md`.

## Versioning

The MCP server follows the same release cycle as Inventoros core. Tools may be added in minor releases; signature-breaking changes wait for a major version bump.
