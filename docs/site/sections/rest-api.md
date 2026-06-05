Inventoros offers programmatic access to every resource: products, stock, orders, suppliers, purchase orders, work orders, and more. Data is available over a REST API and an equivalent GraphQL endpoint.

### Endpoints at a glance

- REST: `{your-host}/api/v1`
- GraphQL: `{your-host}/graphql`
- OpenAPI 3.0 spec and interactive docs: `{your-host}/docs/api`
- Auth: Sanctum bearer token

`{your-host}` matches the `APP_URL` value in your `.env` (for example `http://localhost` or `https://inventoros.example.com`).

### Authentication

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

The response contains a `token` shown only once. Store it securely. It inherits the user's organization scope and permissions.

Token management endpoints:

- `POST /api/v1/login`. Issue a new token (rate-limited 5/min/IP).
- `POST /api/v1/logout`. Revoke the current token.
- `GET /api/v1/user`. Get the authenticated user and permissions.
- `POST /api/v1/tokens`. Create a named token with optional ability scopes.
- `DELETE /api/v1/tokens/{tokenId}`. Revoke a specific token by id.

### Multi-tenancy

Every record carries an `organization_id`, and the API automatically scopes requests to the authenticated user's organization. Cross-organization access returns `404 not_found`, never `403`, so the existence of other organizations' resources is never leaked.

### Permissions

Routes are guarded by `api.permission:` middleware. The token's user must hold one of the listed permissions; the middleware accepts `|`-separated permissions and grants access when the user holds any of them. Common strings:

- `view_products`, `manage_products`
- `view_orders`, `manage_orders`
- `view_suppliers`, `manage_suppliers`
- `view_purchase_orders`, `manage_purchase_orders`, `edit_purchase_orders`, `receive_purchase_orders`
- `manage_stock`, `view_stock_adjustments`, `view_stock_audits`
- `view_warehouses`, `manage_warehouses`
- `view_categories`, `manage_categories`
- `view_locations`, `manage_locations`
- `view_reports`, `view_roles`, `manage_roles`

A request lacking the required permission returns `403 forbidden`.

### Rate limits

- Default: 60 requests per minute per user (or per IP if anonymous), keyed across the entire `/api/v1/*` group.
- `POST /api/v1/login`: 5 requests per minute per IP.

Exceeded limits return `429 Too Many Requests` with a `Retry-After` header. Successful responses include `X-RateLimit-Limit` and `X-RateLimit-Remaining`.

### Pagination

List endpoints return Laravel's standard envelope:

```json
{
  "data":  [],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta":  { "current_page": 1, "from": 1, "last_page": 3, "per_page": 15, "to": 15, "total": 42 }
}
```

Two query params apply to every list endpoint:

- `per_page`, items per page (default 15, max 100)
- `page`, page number (1-indexed)

Most lists also accept `search`, `sort_by`, and `sort_dir` (`asc` or `desc`, default `desc`).

### Error envelope

Every error returns JSON with the same shape:

```json
{
  "message": "Human-readable summary",
  "error":   "machine_readable_code"
}
```

Validation errors additionally include the standard Laravel `errors` map. Common codes: `unauthenticated`, `forbidden`, `not_found`, `insufficient_stock`, `cannot_send`, `cannot_receive`, `cannot_cancel`.

### Resources

All paths are relative to `/api/v1`. See the OpenAPI spec for full schemas:

- Auth: login / logout / user / token CRUD
- Products: CRUD, plus nested options, variants, components, batches, serials
- Categories, Locations, Warehouses: CRUD
- Orders: CRUD with line items; auto-decrements stock
- Stock Adjustments: record signed deltas with a reason
- Stock Audits: read-only view of cycle counts
- Suppliers: CRUD
- Purchase Orders: CRUD plus `send`, `receive`, `cancel`
- Work Orders: read plus `start`, `complete`, `cancel`
- Barcode lookup: `GET /barcode/{code}`
- Permission Sets, Saved Reports: admin surfaces

### Examples

List low-stock products:

```bash
curl -G "${APP_URL}/api/v1/products" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Accept: application/json" \
  --data-urlencode "low_stock=1" \
  --data-urlencode "per_page=25"
```

Create an order:

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

Adjust stock:

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

JavaScript (fetch):

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

PHP (using the Http facade):

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

### GraphQL

The same data is available via GraphQL at `POST /graphql`, powered by `rebing/graphql-laravel`. Authentication is the same Sanctum bearer token. The schema covers products, orders, suppliers, purchase orders, stock adjustments, locations, and categories. Use any GraphQL client (Apollo, urql, graphql-request, and so on).

### Versioning

The current API is `v1`, served at `/api/v1`. Future incompatible changes will land at `/api/v2`; `v1` will continue to receive non-breaking additions.
