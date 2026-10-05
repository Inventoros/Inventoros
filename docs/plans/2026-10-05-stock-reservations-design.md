# Stock reservations and available-to-promise: design

Status: proposed, not implemented. Plugin SDK gap G4.

## Why

Eight planned plugins need to hold stock that is on hand but promised elsewhere: Rental (units out on hire or booked), Wave Picking (lines allocated to a wave), 3PL (client-owned stock held for an outbound), Kitting (components set aside for a kit run), Consignment, and the Shopify, WooCommerce and Amazon integrations (stock published to a channel minus what is already committed). Today Inventoros has one number per product or variant, `stock` (on hand), plus per-location bins; every availability check compares a request against on hand. A plugin cannot keep core from selling, transferring or writing off units it has promised, and channel syncs cannot publish "available" rather than "on hand".

A plugin cannot fix this from outside: the checks live inside core services and must run under the same row lock as the decrement.

## Why it was not built in the plugin SDK round 2 change

The extension point is only safe if every core path that takes stock out honours it, and there are at least eight of them, each with its own locking and error handling:

| Path | Where the availability check is today |
|------|----------------------------------------|
| Order create (web, REST, GraphQL, MCP, import) | `OrderService::create()` running balance per product or variant |
| Order edit (line changes) | `OrderService::replaceItems()` running balance |
| Stock adjustment with `allowNegative = false` | `StockAdjustment::adjust()` / `adjustVariant()` |
| Bin moves and depletion | `ProductLocationStockService::move()`, `consume()`, `applyDelta()` |
| Stock transfers | `StockTransferService` (per bin) |
| Serial and batch allocation | `TrackedStockAllocationService` |
| Work order component consumption | `WorkOrderService` / `Api\WorkOrderController` |
| Approval execution | `ApprovalService` re-running an adjustment |

Changing all of them at once changes what "insufficient stock" means for every user, including installs with no plugins, and needs product decisions (below) that should not be made inside an SDK change. The round 2 change instead ships the read side plugins need first: `stock_changed` from every surface, with before/after and bin detail, which is enough for channel syncs to publish on-hand today.

## Proposed model

### Table `stock_reservations`

| Column | Notes |
|--------|-------|
| `id` | |
| `organization_id` | FK, cascade; `OrganizationScope` |
| `product_id` | FK, cascade |
| `product_variant_id` | nullable FK, cascade; a variant line reserves the variant |
| `location_id` | nullable FK; null = reserved against the product total, set = against one bin |
| `quantity` | unsigned integer, > 0 |
| `owner_type` | `{plugin-slug}` or `core` |
| `owner_reference` | string, the owner's id for the hold (a rental booking, a wave), unique with `owner_type` |
| `expires_at` | nullable; a sweeper releases expired holds |
| `consumed_at`, `released_at` | nullable; an active hold has both null |
| timestamps | |

Indexes: `(organization_id, product_id, product_variant_id)` for the sum; unique `(organization_id, owner_type, owner_reference, product_id, product_variant_id, location_id)` so a retried plugin call is idempotent.

### Available to promise

```
reserved(product, variant, location?) = SUM(quantity) of active, unexpired holds
available = on_hand - reserved
```

Computed in one query, never stored, so it cannot drift. A `product_available_quantity` filter lets a plugin adjust the figure shown on product pages and the REST resource (never the checks).

### Service (the plugin API)

```php
StockReservationService::reserve(Product $product, ?ProductVariant $variant, int $quantity, string $owner, string $reference, ?int $locationId = null, ?CarbonInterface $expiresAt = null): StockReservation
StockReservationService::release(string $owner, string $reference): int   // returns units released
StockReservationService::consume(string $owner, string $reference, Order|Model $by): int
StockReservationService::available(Product $product, ?ProductVariant $variant = null, ?int $locationId = null): int
```

- `reserve()` locks the product (or variant) row like `StockAdjustment::adjust()` does, checks `available >= quantity`, and throws `InsufficientStockException` otherwise. Over-reserving is never allowed.
- `consume()` marks the hold consumed inside the same transaction as the core operation that takes the stock out (for example an order created by the plugin), so the units are not counted twice.
- Plugins reserve only through the service; the table is not a plugin table, and a plugin's holds are released when it is uninstalled (`owner_type = slug`).

### What core checks change

Each path in the table above compares the request to `available` instead of `on_hand`, except:

- A hold's own consumer: an order that carries `reservation_reference` checks against `available + that hold`, then consumes it.
- Physical corrections: a recount or a damage write-off reflects reality and must not be refused because stock is promised. They may take `available` below zero; core then raises a `reservation_shortfall` action so the owning plugin can react (re-plan a wave, warn about a rental).

### Decisions needed before building

1. Do plain web orders respect holds by default, or only when an organization turns reservations on? (Recommendation: on, because with no holds `available == on_hand` and nothing changes.)
2. Location-scoped holds: does a product-level hold block a bin transfer when the bin itself is free? (Recommendation: product-level holds constrain totals only; bin holds constrain their bin.)
3. Serial and batch holds (Rental reserves specific serials): a second table keyed by `product_serial_id`, or a nullable column here.
4. UI: show "on hand / reserved / available" on product and variant pages and in the REST resource (`available_stock`), with a list of holds and their owners for admins.

### Hooks

- `stock_reserved($reservation)`, `stock_reservation_released($reservation)`, `stock_reservation_consumed($reservation, $by)`, all after commit.
- `stock_changed` gains `$change['reserved'] = ['before' => ..., 'after' => ...]` when holds change in the transaction, so channel syncs publish available stock.

### Tests the implementation must carry

- Inventory integrity: for every path in the table, with a hold present, a request above `available` fails and leaves stock, bins, ledger and holds unchanged; a request within it succeeds.
- Concurrency: two `reserve()` calls racing for the last units (MySQL and PostgreSQL row locks), and `reserve()` racing an order.
- Idempotency: the same owner and reference reserve once.
- Expiry sweeper releases only expired, active holds; consumption is exactly once.
- Tenant isolation, and uninstall releasing only that plugin's holds.
- No-hold installs: every existing stock test passes unchanged (`available == on_hand`).

## Recommendation

Build it as its own core change before the Rental plugin, after the four decisions above are made, with the integrity tests written first against each path in the table.
