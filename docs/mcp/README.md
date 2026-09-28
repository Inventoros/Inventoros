# Inventoros MCP Server

Inventoros ships with a built-in [Model Context Protocol](https://modelcontextprotocol.io/) server so AI clients (Claude Desktop, Claude Code, Cursor, custom agents, etc.) can read inventory state and act on it on a user's behalf.

The server is built on the official [`laravel/mcp`](https://github.com/laravel/mcp) package. Every request authenticates with the same Sanctum bearer token used by the REST API; the agent inherits the user's organization scope and permission set.

---

## Endpoint

```
POST {APP_URL}/mcp
```

Transport: streamable HTTP (per the 2025-11-25 MCP spec). The same URL handles `initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read`, `prompts/list`, `prompts/get`, `ping`, and `completion/complete`.

`GET` and `DELETE` against `/mcp` return `405 Method Not Allowed` by design — only `POST` is supported.

## Authentication

Every request must include a Sanctum personal-access token:

```
Authorization: Bearer {token}
```

Get a token from the REST API:

```bash
curl -X POST "${APP_URL}/api/v1/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"you@example.com","password":"...","device_name":"Claude Desktop"}'
```

The token's user provides the **organization scope** (every list/read/write is filtered by `organization_id`) and the **permission set** (each tool checks `hasAnyPermission([...])` before executing).

Requests without a valid token receive `401`. Tools and resources called through an authenticated request but lacking the right permission return an MCP error response with `isError: true`.

## Rate limiting

The MCP route is bound to the same `throttle:api` group as the REST API: **60 requests per minute per authenticated user**. Bursty agents will see `429` with a `Retry-After` header just like the REST API.

## Multi-tenancy

Every tool, resource, and prompt scopes its queries to the authenticated user's `organization_id`. A token issued to org A cannot list, read, or mutate org B's data — attempts return a not-found–style error rather than `403`, to avoid leaking the existence of other organizations' rows.

---

## Connecting a client

Inventoros is a remote MCP server: streamable HTTP with a bearer token. The token goes in an `Authorization` header.

### Claude Code

Add the server from a terminal:

```bash
claude mcp add --transport http inventoros https://inventoros.example.com/mcp \
  --header "Authorization: Bearer 1|paste-your-token-here"
```

Add `--scope user` to make it available in every project, or `--scope project` to write it to a shared `.mcp.json` in the current project (keep the token out of version control). Run `claude mcp list` to check the connection, or `/mcp` inside a session.

### Claude Desktop

Claude Desktop's config file (`claude_desktop_config.json`, opened from Settings > Developer > Edit Config) starts local servers. Bridge to the remote endpoint with [`mcp-remote`](https://www.npmjs.com/package/mcp-remote), which needs Node.js:

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

The header value is passed through an environment variable because some platforms split arguments on the space in `Bearer <token>`. Restart Claude Desktop afterwards.

### Cursor and other clients

Clients that support remote servers with custom headers take the URL and header directly. For Cursor, add this to `~/.cursor/mcp.json` (or a workspace `.cursor/mcp.json`):

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

Call `who_am_i` first to confirm the token works.

---

## Tool catalog

30 tools across 9 groups. Tools marked **destructive** change data; clients should confirm before calling them.

### Identity

| Tool | Permissions | Purpose |
|---|---|---|
| `who_am_i` | none | Returns the authenticated user, organization, role, and the permissions this token holds. Run this first to confirm the token is wired up. |

### Catalog (read)

| Tool | Permissions | Purpose |
|---|---|---|
| `list_products` | `view_products` | Paginated product list with search, category, warehouse, low-stock filters. |
| `search_products` | `view_products` | Lightweight substring search returning up to 25 matches. |
| `get_product` | `view_products` | Single product with category, location, suppliers, options, active variants. |
| `lookup_barcode` | `view_products` | Exact match on barcode/SKU across products and variants. |
| `list_categories` | `manage_categories` or `view_products` | Category list. |
| `list_locations` | `manage_locations` or `view_products` | Storage locations, optionally filtered by warehouse. |
| `list_warehouses` | `view_warehouses` or `view_products` | Warehouse list. |

### Stock

| Tool | Permissions | Purpose |
|---|---|---|
| `list_low_stock` | `view_products` | Products at or below `min_stock`, sorted by shortage. |
| `adjust_stock` | `manage_stock` | Apply a signed delta with reason (`manual`, `count`, `damage`, `return`, `transfer`), optionally at a `location_id` bin (required for users restricted to assigned warehouses). **Destructive**. |

### Sales

| Tool | Permissions | Purpose |
|---|---|---|
| `list_orders` | `view_orders` | Paginated orders with filters for status, source, warehouse, date range. |
| `get_order` | `view_orders` | Single order with line items. |
| `create_order` | `create_orders` | Create an order; decrements stock per item; fails if any line is short. Accepts optional line and order discounts (applied before tax). **Destructive**. |
| `email_order_invoice` | `edit_orders` | Email the order's invoice PDF to the customer (or `to`), with optional CC and message. Assigns the invoice number on first use. **Destructive**. |
| `record_payment` | `record_payments` | Record a payment against an order. Partial payments are fine; a payment above the balance due needs `allow_overpayment`; cancelled orders are refused. **Destructive**. |

### Shipping

| Tool | Permissions | Purpose |
|---|---|---|
| `list_shipments` | `view_shipments` | Paginated shipments with carrier, tracking and status; filter by order or status. |
| `create_shipment` | `create_shipments` | Record a shipment with manual tracking, optionally marking it shipped. **Destructive**. |

### Purchasing

| Tool | Permissions | Purpose |
|---|---|---|
| `list_suppliers` | `view_suppliers` | Supplier list with search and active filter. |
| `list_purchase_orders` | `view_purchase_orders` | Paginated POs with status / supplier filters. |
| `get_purchase_order` | `view_purchase_orders` | Single PO with supplier and line items. |
| `create_purchase_order` | `create_purchase_orders` | Create a draft PO. Does not affect stock. **Destructive**. |
| `send_purchase_order` | `edit_purchase_orders` | Transition draft to sent. **Destructive** and idempotent. |
| `receive_purchase_order` | `receive_purchase_orders` | Receive items; writes stock; transitions to partial/received. **Destructive**. |
| `submit_purchase_order_for_approval` | `edit_purchase_orders` | Put a draft PO in front of the approvers when the organization requires approval. **Destructive**. |

### Approvals

| Tool | Permissions | Purpose |
|---|---|---|
| `list_pending_approvals` | no single permission | Returns only the purchase orders, stock adjustment requests and stock transfers the caller may decide. Each item carries the `type` and `id` to pass to `decide_approval`. |
| `decide_approval` | `approve_purchase_orders`, `approve_stock_adjustments` or `approve_stock_transfers`, by `type` | Approve or reject a pending request; rejecting needs notes. **Destructive**. |

### Manufacturing

| Tool | Permissions | Purpose |
|---|---|---|
| `list_work_orders` | `manage_stock` | Paginated work orders with status filter. |
| `start_work_order` | `manage_stock` | Validate component stock and transition to in_progress. **Destructive**. |
| `delete_work_order` | `manage_stock` | Delete a draft or cancelled work order. **Destructive**. |

### Catalog (write)

| Tool | Permissions | Purpose |
|---|---|---|
| `create_product` | `create_products` | Create a new product. **Destructive**. |

Tool names changed in v2.0.0 from the kebab-case class names (`list-orders-tool`) to the snake_case names above. See `UPGRADE.md` for the full mapping.

---

## Resources

Resources are browsable read-only data the agent can fetch without arguments.

| URI | Purpose |
|---|---|
| `inventoros://low-stock` | Snapshot of every product at or below its `min_stock`. |
| `inventoros://orders/recent` | The 25 most recent sales orders. |

## Prompts

| Prompt | Arguments | Purpose |
|---|---|---|
| `reorder_helper` | `warehouse_id` (optional) | Walks the user through low-stock review, supplier grouping, draft PO creation, and (after confirmation) sending. |

---

## Annotations

Tools that mutate state carry the standard MCP `annotations`:

- `IsReadOnly` — safe to call with no side effects (every list/get/lookup tool).
- `IsDestructive` — the agent should ask for confirmation before invoking. Used on every tool marked **destructive** in the catalog above.
- `IsIdempotent` — re-running with the same arguments has the same effect (PO send).

Compliant clients use these to shape their UI (e.g. surface a confirmation prompt before invoking destructive tools).

## Error model

Errors are returned as MCP `isError: true` responses with a human-readable string:

- **Unauthenticated** — "MCP requests require an authenticated Sanctum token." (covered by middleware before reaching tools, returns HTTP 401).
- **Forbidden** — "Token lacks any of the required permissions: ..." with the list of permissions any of which would have allowed the call.
- **Not found** — "Product not found in this organization." (and equivalents for orders, POs, work orders). Cross-organization access surfaces here, not as a 403.
- **Validation** — Comma-joined Laravel validation messages for the offending fields.
- **Domain** — e.g. "Cannot remove 100 units; only 5 on hand.", "Purchase order in status [received] cannot receive items."

## Local testing

The `laravel/mcp` package ships test helpers used in `tests/Feature/Mcp/InventorosMcpServerTest.php`:

```php
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\ListProductsTool;

InventorosServer::actingAs($user)
    ->tool(ListProductsTool::class, ['search' => 'widget'])
    ->assertOk()
    ->assertSee('widget');
```

`Server::actingAs($user)` forces the Sanctum guard to the given user; subsequent `tool()`, `resource()`, or `prompt()` calls go through the same dispatch path as a real HTTP request would.

## Adding a new tool

1. Generate a class under `app/Mcp/Tools/`:
   ```bash
   php artisan make:mcp-tool MyNewTool
   ```
   (the `laravel/mcp` package registers this command).

2. Use the `App\Mcp\Concerns\AuthenticatesMcpRequest` trait to get `user()`, `organizationId()`, and `authorize([...])`.

3. Define `description`, `schema(JsonSchema $schema)`, and `handle(Request $request)`.

4. Annotate destructive tools with `#[IsDestructive]` from `Laravel\Mcp\Server\Tools\Annotations`.

5. Register the class in `app/Mcp/Servers/InventorosServer.php`'s `$tools` array.

6. Add a test in `tests/Feature/Mcp/InventorosMcpServerTest.php`.
