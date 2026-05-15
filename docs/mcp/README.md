# Inventoros MCP Server

Inventoros ships with a built-in [Model Context Protocol](https://modelcontextprotocol.io/) server so AI clients (Claude Desktop, Claude Code, Cursor, ChatGPT, custom agents, etc.) can read inventory state and act on it on a user's behalf.

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

## Connecting from Claude Desktop / Claude Code

Add an entry to your client's MCP config. For Claude Desktop on macOS (`~/Library/Application Support/Claude/claude_desktop_config.json`) or Claude Code (`~/.claude/mcp.json`):

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

Restart the client. The Inventoros tools will appear in the tool picker.

---

## Tool catalog

### Identity

| Tool | Purpose |
|---|---|
| `who_am_i` | Returns the authenticated user, organization id, role, and admin/manager flags. Run this first to confirm the token is wired up. |

### Catalog (read)

| Tool | Permissions | Purpose |
|---|---|---|
| `list_products` | `view_products` or `manage_products` | Paginated product list with search, category, warehouse, low-stock filters. |
| `search_products` | `view_products` or `manage_products` | Lightweight substring search returning up to 25 matches. |
| `get_product` | `view_products` or `manage_products` | Single product with category, location, suppliers, options, active variants. |
| `lookup_barcode` | `view_products` or `manage_products` | Exact match on barcode/SKU across products and variants. |
| `list_categories` | `view_categories` or `view_products` | Category list. |
| `list_locations` | `view_locations` or `view_products` | Storage locations, optionally filtered by warehouse. |
| `list_warehouses` | `view_warehouses` or `view_products` | Warehouse list. |

### Stock

| Tool | Permissions | Purpose |
|---|---|---|
| `list_low_stock` | `view_products` or `manage_products` | Products at or below `min_stock`, sorted by shortage. |
| `adjust_stock` | `manage_stock` | Apply a signed delta with reason (`manual`, `count`, `damage`, `return`, `transfer`). Marked **destructive**. |

### Sales

| Tool | Permissions | Purpose |
|---|---|---|
| `list_orders` | `view_orders` or `manage_orders` | Paginated orders with filters for status, source, warehouse, date range. |
| `get_order` | `view_orders` or `manage_orders` | Single order with line items. |
| `create_order` | `manage_orders` | Create order; decrements stock per item; fails if any line is short. Marked **destructive**. |

### Purchasing

| Tool | Permissions | Purpose |
|---|---|---|
| `list_suppliers` | `view_suppliers` or `manage_suppliers` | Supplier list with search and active filter. |
| `list_purchase_orders` | `view_purchase_orders` or `manage_purchase_orders` | Paginated POs with status / supplier filters. |
| `get_purchase_order` | `view_purchase_orders` or `manage_purchase_orders` | Single PO with supplier and line items. |
| `create_purchase_order` | `manage_purchase_orders` | Create draft PO. Does not affect stock. Marked **destructive**. |
| `send_purchase_order` | `edit_purchase_orders` or `manage_purchase_orders` | Transition draft → sent. Marked **destructive** + **idempotent**. |
| `receive_purchase_order` | `receive_purchase_orders` or `manage_purchase_orders` | Receive items; writes stock; transitions to partial/received. Marked **destructive**. |

### Manufacturing

| Tool | Permissions | Purpose |
|---|---|---|
| `list_work_orders` | `manage_stock` | Paginated work orders with status filter. |
| `start_work_order` | `manage_stock` | Validate component stock and transition to in_progress. Marked **destructive**. |

### Catalog (write)

| Tool | Permissions | Purpose |
|---|---|---|
| `create_product` | `manage_products` | Create a new product. Marked **destructive**. |

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
- `IsDestructive` — the agent should ask for confirmation before invoking. Used on stock adjustments, order creation, PO creation, PO send, PO receive, work-order start, and product create.
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
