Inventoros offers programmatic access to every resource: products, stock, orders, customers, returns, stock transfers, stock audits, suppliers, purchase orders, work orders, webhooks, users, and more. Data is available over a REST API and an equivalent GraphQL endpoint.

## Endpoints at a glance

- REST: `{your-host}/api/v1`
- GraphQL: `{your-host}/graphql`
- OpenAPI 3.1 spec and interactive docs: `{your-host}/docs/api` (sign-in required outside local development unless `API_DOCS_PUBLIC=true`)
- Auth: Sanctum bearer token (the same tokens work for REST, GraphQL and the [MCP server](mcp-server))

`{your-host}` matches the `APP_URL` value in your `.env` (for example `http://localhost` or `https://inventoros.example.com`).

## Authentication

Inventoros uses Laravel Sanctum personal-access tokens. Every request (except `POST /api/v1/login`) must include the bearer token:

```text
Authorization: Bearer {your-token}
```

Get a token by logging in:

```bash
curl -X POST "${APP_URL}/api/v1/login" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"email":"you@example.com","password":"...","device_name":"my-app"}'
```

If the account has two-factor authentication turned on, also send `code` (the current authenticator code) or `recovery_code`; without one the login is a `422`. To get a token for another organization you belong to, send its `organization_id` (the default is your home organization).

The response contains the `token` (with `token_type: "Bearer"` and a short `user` object), shown only once. Store it securely. A login token is not limited to a list of abilities: it carries the user's full permissions in the organization it was issued for.

You can also create tokens in the app under **Settings > API tokens**. There you name the token and tick the permissions it may use, chosen from the permissions you hold; the token is shown once. The same page lists your tokens with when each was last used, and revokes them.

Token management endpoints:

- `POST /api/v1/login`. Issue a new token (rate-limited 5/min/IP).
- `POST /api/v1/logout`. Revoke the current token.
- `GET /api/v1/user`. Get the authenticated user, their organization, every organization they belong to, and their permissions.
- `POST /api/v1/tokens`. Create a named token with optional `abilities` (permission names). A new token is never broader than the token creating it or the user's own permissions, and only admins may request the `*` wildcard.
- `DELETE /api/v1/tokens/{tokenId}`. Revoke one of your tokens by id.

## Multi-tenancy

Every record carries an `organization_id`, and the API scopes each request to the organization the token was issued for. A user who belongs to several organizations needs a token per organization (see `organization_id` on login). Cross-organization access returns `404 not_found`, never `403`, so the existence of other organizations' resources is never leaked.

## Permissions

Routes are guarded by `api.permission:` middleware, one permission per verb (read, create, edit, delete). The token's user must hold the permission, and when the token was created with a list of abilities the token must include it too, so a read-only token stays read-only even for an admin. Permissions by resource:

- Products: `view_products`, `create_products`, `edit_products`, `delete_products`; stock moves `manage_stock`
- Categories: `manage_categories`; locations: `manage_locations`; stock adjustments and work orders: `manage_stock`
- Customers: `view_customers`, `create_customers`, `edit_customers`, `delete_customers` (a customer's orders also need `view_orders`)
- Orders: `view_orders`, `create_orders`, `edit_orders` (also emailing the invoice), `delete_orders`, `approve_orders`
- Payments: `view_payments`, `record_payments` (payments, refunds and voids); shipments: `view_shipments`, `create_shipments`
- Returns: `manage_returns`
- Stock transfers: `transfer_stock`
- Stock audits: `view_stock_audits` or `manage_stock_audits` to read, `create_stock_audits` to create, `manage_stock_audits` to start, count and complete
- Suppliers and purchase orders: `view_suppliers`, `create_suppliers`, `edit_suppliers`, `delete_suppliers`, `view_purchase_orders`, `create_purchase_orders`, `edit_purchase_orders`, `delete_purchase_orders`, `receive_purchase_orders`
- Warehouses: `view_warehouses`, `create_warehouses`, `edit_warehouses`, `delete_warehouses`
- Approvals: deciding needs the matching `approve_purchase_orders`, `approve_stock_adjustments`, `approve_stock_transfers` or `approve_orders`
- Users: `view_users`, `create_users`, `edit_users`; webhooks: `manage_organization`
- Saved reports: `view_reports`; permission sets: `view_roles`, `create_roles`, `edit_roles`, `delete_roles`

A request lacking the required permission returns `403 forbidden`.

## Rate limits

- Default: 60 requests per minute per user (or per IP if anonymous), keyed across the entire `/api/v1/*` group. `POST /graphql` uses the same limiter.
- `POST /api/v1/login`: 5 requests per minute per IP.

Exceeded limits return `429 Too Many Requests` with a `Retry-After` header. Successful responses include `X-RateLimit-Limit` and `X-RateLimit-Remaining`.

## Pagination

List endpoints return Laravel's standard envelope:

```json
{
  "data":  [],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta":  { "current_page": 1, "from": 1, "last_page": 3, "per_page": 15, "to": 15, "total": 42 }
}
```

Two query params apply to every paginated list endpoint:

- `per_page`, items per page (default 15, max 100)
- `page`, page number (1-indexed)

Most lists also accept `search`, `sort_by`, and `sort_dir` (`asc` or `desc`, default `desc`).

A few lists differ. `GET /warehouses` and `GET /work-orders` return Laravel's plain paginator, with `current_page`, `per_page`, `total` and the page links at the top level next to `data`. `GET /shipments` and `GET /orders/{id}/shipments` return `data` with a short `meta` (`current_page`, `per_page`, `total`, `last_page`) and no `links`. Small nested lists (product options, variants and components, order payments, approvals, permission sets, saved reports) are not paginated.

## Error envelope

Errors raised by Inventoros return JSON with this shape:

```json
{
  "message": "Human-readable summary",
  "error":   "machine_readable_code"
}
```

Some errors come straight from the framework and carry only a `message`: a missing or invalid token (`401`, `"Unauthenticated."`), a rate limit (`429`), and a warehouse restriction (`403`). Validation errors (`422`) carry `message` and the standard Laravel `errors` map. An id in the request body that belongs to another organization is a validation error. Common codes: `unauthenticated`, `forbidden`, `not_found`, `insufficient_stock`, `invalid_status`, `already_processed`, `missing_recipient`, `has_orders`, `cannot_send`, `cannot_receive`, `cannot_cancel`.

## Resources

All paths are relative to `/api/v1`. See the OpenAPI spec for full schemas:

- Auth: login / logout / user / token CRUD
- Products: CRUD, plus nested options, variants, components, batches, serials
- Categories, Locations, Warehouses: CRUD
- Orders: CRUD with line items; stock is decremented when the order is created. Plus `approve`, `reject` and `invoice/email`. Optional line and order discounts; totals are always computed on the server
- Order payments: `GET /orders/{id}/payments` (`view_payments`), `POST /orders/{id}/payments`, `POST /orders/{id}/refunds` and `POST /orders/{id}/payments/{payment}/void` (`record_payments`)
- Customers: CRUD plus `GET /customers/{id}/orders`
- Returns (RMA): list, show, create, then `approve`, `receive` (restocks), `complete` or `reject`
- Stock Transfers: list, show, create, then `ship`, `complete` (moves stock between location bins) or `cancel`
- Shipments: `GET /shipments`, `GET /shipments/{id}`, `GET /orders/{id}/shipments`, create with `POST /orders/{id}/shipments` (manual or EasyPost), then `rates`, `buy-label`, `void-label`, `ship` (`view_shipments` / `create_shipments`; see [Shipping & Carriers](shipping))
- Stock Adjustments: list, show, and record signed deltas with a `type` (`manual`, `count`, `damage`, `return`, `transfer`) and an optional reason. When the organization's approval rules cover an adjustment it is held for approval and the response is `202` with `status: "pending_approval"`
- Stock Audits: list, show, create, `start`, `items/{item}/count`, `complete` (books recount adjustments)
- Suppliers: CRUD
- Purchase Orders: CRUD plus `send`, `receive`, `cancel` and `submit-for-approval`
- Approvals: `GET /approvals` (what the caller may decide), `GET /approvals/mine` (the caller's own requests), and `POST /approvals/{type}/{id}/approve` or `/reject` for a `purchase_order`, `stock_adjustment`, `stock_transfer` or `sales_order` (needs the matching `approve_*` permission; rejecting needs notes)
- Work Orders: list, show, create, then `start`, `complete`, `cancel`, and delete while draft or cancelled
- Webhooks: CRUD, `regenerate-secret` and `deliveries`. The signing secret is returned only on create and regenerate
- Users: list, show, create and update, with the same role-assignment guards as the web app
- Barcode lookup: `GET /barcode/{code}` resolves a barcode, SKU or location code to a product, variant or location
- Permission Sets: CRUD plus `GET /permission-sets/categories`
- Saved Reports: CRUD plus `GET /reports/{id}/export`

Users assigned to specific warehouses only see and act on locations, stock adjustments, stock audits, stock transfers (either end), returns (by where their goods are restocked) and purchase order receiving in those warehouses, over REST and GraphQL alike; anything else returns `403`. Admins and roles with `access_all_warehouses` are never restricted.

## Examples

### List low-stock products

```bash
curl -G "${APP_URL}/api/v1/products" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Accept: application/json" \
  --data-urlencode "low_stock=1" \
  --data-urlencode "per_page=25"
```

### Create an order

```bash
curl -X POST "${APP_URL}/api/v1/orders" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_name": "Acme Corp",
    "currency": "USD",
    "items": [
      { "product_id": 42, "quantity": 2 },
      { "product_id": 99, "quantity": 1, "unit_price": 49.95 }
    ]
  }'
```

Discounts are optional. Each line may carry `discount_type` (`percent` or `fixed`) and `discount_value`, and so may the order. A line discount comes off that line's quantity times unit price; the order discount then comes off the merchandise net of line discounts. Discounts are applied before tax and never reduce tax or shipping, so the tax you send should be computed on the discounted amounts. The response's `discount_amount` is the whole discount, so `subtotal - discount_amount + tax + shipping = total`. A discount larger than the amount it applies to, or a percentage over 100, is a `422`.

An order is in the organization's currency unless you send `currency`. A line without `unit_price` is priced in the order's currency: the product's own price when the order is in the product's currency, otherwise the product's price for that currency (the per-currency prices on the product; a variant with a price of its own has none). If the product has no price in the order's currency the line is a `422` on `items.N.unit_price`; send a `unit_price` for it.

Money is kept to the cent. Unit prices, taxes, shipping, discount values (fixed amounts and percentages alike, so `12.5` is fine and `12.345` is not) and payment amounts accept at most two decimal places; more is a `422` rather than a silent rounding. Computed figures (line totals, percentage discounts) are rounded half up to the cent.

### Record a payment

Partial payments are fine; a payment above the balance due is rejected unless `allow_overpayment` is `true`, and cancelled orders take no payments.

```bash
curl -X POST "${APP_URL}/api/v1/orders/1234/payments" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{ "amount": 50.00, "method": "card", "reference": "ch_123" }'
```

`method` is one of `cash`, `card`, `bank_transfer`, `cheque`, `other`. The order then reports `amount_paid`, `balance_due` and `payment_status` (`unpaid`, `partial`, `paid`, `overpaid`, `refunded`, or `untracked` for orders from before payment tracking that have no payments yet), visible to tokens with `view_payments`. Refunds cannot exceed what was paid. Payments are never edited or deleted, only voided, and each change fires a `payment.recorded` or `payment.voided` webhook.

### Adjust stock

```bash
curl -X POST "${APP_URL}/api/v1/stock-adjustments" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "product_id": 42,
    "quantity": -3,
    "type": "damage",
    "reason": "Dropped pallet"
  }'
```

### JavaScript (fetch)

```javascript
const APP_URL = "https://your-host";
const TOKEN   = "1|paste-your-token-here";

const api = (path, init = {}) =>
  fetch(`${APP_URL}/api/v1${path}`, {
    ...init,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: `Bearer ${TOKEN}`,
      ...(init.headers || {}),
    },
  }).then(async (r) => {
    const body = await r.json().catch(() => ({}));
    if (!r.ok) throw Object.assign(new Error(body.message), { status: r.status, body });
    return body;
  });

const { data: products } = await api("/products?low_stock=1");
```

### PHP (Laravel Http client)

```php
use Illuminate\Support\Facades\Http;

$client = Http::baseUrl(config('services.inventoros.url').'/api/v1')
    ->withToken(config('services.inventoros.token'))
    ->acceptJson();

$products = $client->get('products', [
    'search'   => 'widget',
    'per_page' => 25,
])->throw()->json('data');

$order = $client->post('orders', [
    'customer_name' => 'Acme Corp',
    'currency'      => 'USD',
    'items'         => [
        ['product_id' => 42, 'quantity' => 2],
    ],
])->throw()->json('data');
```

### Receive a return

```bash
curl -X POST "${APP_URL}/api/v1/returns" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"order_id": 1042, "type": "return", "reason": "Damaged in transit",
       "items": [{ "order_item_id": 5531, "quantity": 2, "condition": "damaged", "restock": false }]}'

curl -X POST "${APP_URL}/api/v1/returns/17/approve" -H "Authorization: Bearer ${TOKEN}"
curl -X POST "${APP_URL}/api/v1/returns/17/receive" -H "Authorization: Bearer ${TOKEN}"
```

Until a return is received you can change each line's restock flag and condition. Send only the fields you want to change; a line that is not on the return is a validation error, and a received, completed or rejected return answers 422 with `invalid_status`:

```bash
curl -X PATCH "${APP_URL}/api/v1/returns/17/items" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"items": [{ "id": 88, "restock": true, "condition": "used" }]}'
```

## Webhook events

Subscribe a URL to any of these events under **Settings > Webhooks** or with `POST /api/v1/webhooks`. Each delivery is signed with HMAC-SHA256 of the request body using the webhook's secret, sent in the `X-Webhook-Signature` header (alongside `X-Webhook-Event` and `X-Webhook-Delivery`), and fires only after the change is committed. Active plugins can register events of their own.

| Group | Events |
| --- | --- |
| Product | `product.created`, `product.updated`, `product.deleted`, `product.low_stock`, `product.out_of_stock` |
| Order | `order.created`, `order.updated`, `order.status_changed`, `order.approved`, `order.rejected` |
| Payment | `payment.recorded`, `payment.voided` (a refund is a recorded payment of type `refund`) |
| Shipment | `shipment.created`, `shipment.delivered` |
| Stock | `stock.adjusted` |
| Purchase order | `purchase_order.created`, `purchase_order.received`, `purchase_order.cancelled` |
| Customer | `customer.created`, `customer.updated`, `customer.deleted` |
| Return | `return.created`, `return.received` |
| Stock transfer | `transfer.created`, `transfer.completed` |
| Work order | `work_order.completed` |
| Stock audit | `stock_audit.completed` |

## GraphQL

The same data is available via GraphQL at `POST /graphql` (`GET` is accepted too), powered by `rebing/graphql-laravel`. Authentication is the same Sanctum bearer token. Use any GraphQL client (Apollo, urql, graphql-request, and so on).

Queries: `products`, `product`, `orders`, `order`, `suppliers`, `supplier`, `purchaseOrders`, `purchaseOrder`, `stockAdjustments`, `locations`, `categories`, `customers`, `customer`, `returnOrders`, `returnOrder`, `stockTransfers`, `stockTransfer`, `users`, `user`, `productVariants`, `shipments` and `pendingApprovals`.

Users are read-only over GraphQL. Orders carry their `shipments`, and their `payments`, `amount_paid`, `balance_due` and `payment_status` when the user and token hold `view_payments`.

Mutations: `createProduct`, `updateProduct`, `deleteProduct`, `createOrder`, `updateOrder`, `createStockAdjustment`, `requestStockAdjustmentApproval`, `createSupplier`, `updateSupplier`, `createCustomer`, `updateCustomer`, `createReturnOrder`, `approveReturnOrder`, `receiveReturnOrder`, `createStockTransfer`, `completeStockTransfer`, `createPurchaseOrder`, `updatePurchaseOrder`, `receivePurchaseOrder` and `decideApproval`.

Every field is gated like the matching REST route: the user must hold the permission and the token must allow it, so a scoped token is enforced the same way over GraphQL. Mutations call the same services as the web app and REST API, so a return received or a transfer completed over GraphQL restocks and moves bins exactly as it would anywhere else. List queries accept `limit` (default 50, max 100), except `pendingApprovals`, which takes none. The endpoint shares the REST rate limit of 60 requests per minute.

```bash
curl -X POST "${APP_URL}/graphql" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"query":"{ stockTransfers(status: \"pending\") { transfer_number from_location { name } to_location { name } items { quantity product { sku } } } }"}'
```

## Versioning

The current API is `v1`, served at `/api/v1`. Future incompatible changes will land at `/api/v2`; `v1` will continue to receive non-breaking additions.
