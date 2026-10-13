# Stock reservations and available-to-promise: design

Status: implemented (Inventoros 2.1). Plugin SDK gap G4. The four decisions are settled below ("Decisions"); the plugin guide (docs/PLUGIN_DEVELOPMENT.md, "Stock reservations") is the reference for plugin authors.

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

### Decisions

1. Holds are always respected; there is no per-organization toggle. With no holds `available == on_hand`, so installs without reservations behave exactly as before (the whole existing suite passes unchanged).
2. Product-level holds constrain totals only; bin (location) holds constrain their bin, and count toward the total too. A transfer between bins is limited only by holds in its source bin.
3. Serial and batch holds are nullable columns on `stock_reservations`: `product_serial_id` (quantity must be 1; the serial must be available and not already held) and `product_batch_id` (at most the batch's unheld quantity). Both count toward the product total. A held serial is skipped by allocation and cannot be marked sold, reserved or damaged through the serial API until its hold is consumed or released; its consumer (an order carrying the reservation) is allocated exactly that serial. Held batch units are not allocatable to anyone but the consumer.
4. UI: the product page shows on hand / reserved / available, with reserved and available per variant; the REST product and variant resources carry `reserved_stock` and `available_stock`, as do the GraphQL `Product` and `ProductVariant` types and the MCP get, list and barcode lookup payloads. A Reservations page (Stock section) lists holds with owner, reference, scope and expiry, for anyone with `view_products`; releasing one needs the existing `manage_stock` permission (no new permission), is written to the activity log and fires `stock_reservation_released` with `release_reason` `admin`.

### As built

- Table: as above, plus `product_serial_id`, `product_batch_id`, `consumed_by` (morph), `release_reason` (`released`, `admin`, `expired`, `uninstalled`) and `scope_key`. The idempotency index is unique on `(organization_id, owner_type, owner_reference, scope_key)`, where `scope_key` spells out product, variant, location, serial and batch as one non-null string: MySQL, PostgreSQL and SQLite all treat NULLs in a unique index as distinct, so the nullable columns cannot carry it themselves.
- `reserve()` returns the existing hold for a retried call; a different quantity under the same reference is refused (`reservation_conflict`); a released or expired reference is reactivated; a consumed one is refused (`reservation_consumed`).
- An expired hold stops counting at `expires_at`. `reservations:release-expired` (scheduled every five minutes) records the release and fires `stock_reservation_released`.
- Physical corrections are the adjustment types `recount`, `count`, `damage` and `loss` (a bookkeeping `correction` is not: it may not take promised units). They may take available below zero (never on hand, where the path already refuses negative stock) and then fire `reservation_shortfall($product, $variant, $shortfall)` after commit, for the total, a variant or a bin.
- Orders carry the reservation as `reservation_owner` and `reservation_reference` in `OrderService::create()` data. The holds are claimed (marked consumed) under the order's locks before the checks, so the checks see available plus that hold; a failed order rolls the claim back. Edits leave a consumed hold consumed.
- Bin depletion (`ProductLocationStockService::consume()`) draws free units first and leaves held units in their bins; a physical correction draws them last. `applyDelta()` refuses to take a bin below its holds unless the caller says it is a correction. `move()` refuses to move held units out of a bin.
- The GraphQL `createStockAdjustment` mutation now refuses negative stock like the web, REST and MCP adjustments (it used `allowNegative = true`), so it cannot take promised units either.
- A plugin's holds are released in every organization when it is deleted (`PluginService::deletePlugin()`).

### Paths and how each checks

| Path | Check |
|------|-------|
| Order create (web, REST, GraphQL, MCP, import) | `OrderService::create()`: running balance per product or variant starts at on hand less `reservedTotal()`, read after the product and variant row locks. Bin depletion skips held bin units; allocation skips held serials and batch units. |
| Order edit | `OrderService::replaceItems()`: same running balance after the old lines are restocked; the per-line `adjust()`/`adjustVariant()` re-check. |
| Adjustments with `allowNegative = false` | `StockAdjustment::adjust()` / `adjustVariant()` under the row lock, unless a physical correction. Covers manual adjustments on every surface, approval execution, inter-company out and work order consumption. `ApprovalService::requestStockAdjustment()` refuses up front what could never be approved. |
| Bin moves and depletion | `move()` (source bin holds), `consume()` (free units first), `applyDelta()` (bin holds). |
| Stock transfers | `StockTransferService::complete()` through `move()`: limited by holds in the source bin only. |
| Serial and batch allocation | `TrackedStockAllocationService` skips held serials and held batch units; a consumed reservation's serials and batches are allocated first. |
| Serial status changes | `PUT /api/v1/products/{product}/serials/{serial}` refuses to move a held serial out of available (409 `serial_reserved`). |
| Work order start | `WorkOrderService::start()` (web, REST and now MCP) compares remaining requirements with `available()`. |
| Work order completion | Web and REST: `consume()` then `adjust(allowNegative: false)` under the product locks. |
| Approval execution | `ApprovalService` applies a held adjustment through `adjust(allowNegative: false)`, so it re-checks available at approval time. |
| Inter-company out | `InterCompanyTransferService` books `inter_company_out` through `adjust(allowNegative: false)`, with the source bin through `applyDelta()`. |

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
