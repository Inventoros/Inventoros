# Database index audit - 2026-11-01

Inventoros supports MySQL and PostgreSQL. This audit inspected the migrated
schema on **MySQL 8.0.46** and **PostgreSQL 16.15**, the major versions used by
CI, and measured nine representative list/relationship queries on both.
All data and migration experiments used disposable local databases.

## Changes

### Three indexes for the actual list ordering

| Table | Columns, in order | Query served |
| --- | --- | --- |
| `products` | `organization_id, created_at, deleted_at` | Default inventory list and recent dashboard products |
| `orders` | `organization_id, created_at, id, deleted_at` | Recent dashboard orders |
| `purchase_orders` | `organization_id, order_date, id, deleted_at` | Default purchase-order list |

The existing product index starts with `(organization_id, is_active, created_at)`.
`ProductController::index()` does not constrain `is_active`, so that index cannot
directly supply its creation-date ordering. Purchase orders previously had no
tenant/date index. The dashboard orders query uses `created_at`, whereas the
existing order-list index uses `order_date`.

The date keys precede `deleted_at` deliberately. An initial candidate with
`deleted_at` immediately after the tenant removed the sort on MySQL, but
PostgreSQL 16 still sorted the tenant's live rows for `deleted_at IS NULL`.
Moving that key after the ordering columns produced ordered index scans on
both engines. This avoids requiring a PostgreSQL-only partial index. The
soft-delete condition can be checked in the index, but does not bound a
contiguous leading range; tenants with mostly deleted history may benefit
from a separately measured PostgreSQL partial index later.

### Five missing relationship indexes, without duplicating MySQL indexes

| Table | Leading column |
| --- | --- |
| `return_order_items` | `return_order_id` |
| `stock_transfer_items` | `stock_transfer_id` |
| `inter_company_transfer_lines` | `inter_company_transfer_id` |
| `inter_company_transfer_serials` | `inter_company_transfer_line_id` |
| `inter_company_transfer_batches` | `inter_company_transfer_line_id` |

These serve the `items`, `lines`, `serials` and `batches` Eloquent relationships,
including eager loading on list/detail pages. Before this change PostgreSQL
scanned each entire child table. MySQL already had useful indexes because
InnoDB creates a referencing foreign-key index when needed. PostgreSQL does
not create that index automatically. See the official
[MySQL foreign-key documentation](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html)
and [PostgreSQL constraint documentation](https://www.postgresql.org/docs/16/ddl-constraints.html).

The migration checks for an existing B-tree with the required leading column.
It adds these five indexes on PostgreSQL and SQLite, reuses InnoDB's existing
ones on MySQL, and preserves those existing indexes during rollback.

### Five duplicate indexes removed

| Table | Plain index removed | Unique index retained |
| --- | --- | --- |
| `organizations` | `organizations_slug_index` | `organizations_slug_unique` |
| `plugins` | `plugins_slug_index` | `plugins_slug_unique` |
| `products` | `products_organization_id_sku_index` | `products_organization_id_sku_unique` |
| `stock_transfers` | `stock_transfers_organization_id_transfer_number_index` | `stock_transfers_organization_id_transfer_number_unique` |
| `system_settings` | `system_settings_key_index` | `system_settings_key_unique` |

Each pair was an exact B-tree duplicate on both engines. The unique index
continues to support the same lookups and enforce uniqueness. Removal is
conditional on that unique index being present. No uniqueness constraints
or foreign keys were removed. No application index hints refer to these names.

Total index count: **MySQL 377 -> 375; PostgreSQL 281 -> 284**. PostgreSQL's
count includes the ten existing trigram GIN indexes. Removing the duplicate
copies saves index maintenance and storage; write throughput was not benchmarked.

## Evidence

The fixture contained 50,000 rows in each measured list/child table. Products,
orders and purchase orders had ten tenants with 5,000 records each, 10% soft
deletion, and dates distributed across 730 days. Child tables had 5,000 parents
with ten rows per parent. Products included active and inactive records.

`ANALYZE` ran before each query, followed by one warm-up and seven timed reads.
The table below reports **server execution time from the captured EXPLAIN
ANALYZE plan**, in milliseconds. These are individual warm-cache synthetic
measurements, not production latency or endpoint throughput claims. Client
medians and complete plans are in
[the evidence file](2026-11-01-database-index-plans.json).

| Query | MySQL before -> after (ms) | PostgreSQL before -> after (ms) |
| --- | ---: | ---: |
| Inventory, latest 15 | 6.240 -> 0.031 | 1.944 -> 0.042 |
| Dashboard, latest 5 orders | 7.370 -> 0.018 | 2.448 -> 0.021 |
| Purchase orders, latest 15 | 8.440 -> 0.027 | 1.486 -> 0.047 |
| Return items for ten parents | Existing index retained | 2.067 -> 0.024 |
| Stock-transfer items for ten parents | Existing index retained | 2.479 -> 0.028 |
| Inter-company lines for one transfer | Existing index retained | 2.894 -> 0.025 |
| Inter-company serials for one line | Existing index retained | 1.634 -> 0.012 |
| Inter-company batches for one line | Existing index retained | 1.761 -> 0.012 |

The three list queries previously read 5,000 tenant rows and sorted 4,500 live
rows. Afterwards the ordered scans returned the requested 5 or 15 rows without
a sort. PostgreSQL child lookups changed from 50,000-row sequential scans to
index scans returning 10 or 100 rows. MySQL child plans retained their original
foreign-key indexes; timing variation there is not attributed to this change.

The ninth query, the main order list ordered by `order_date DESC, id DESC`,
already performed well using `(organization_id, order_date)`: MySQL used a
reverse index scan; PostgreSQL used an index scan plus a small incremental
sort (22 live rows visited in this fixture). No additional index was added for
that query.

To inspect equivalent plans on a representative database:

```sql
-- MySQL: prefix with EXPLAIN ANALYZE
-- PostgreSQL: prefix with EXPLAIN (ANALYZE, BUFFERS)
SELECT * FROM products
WHERE organization_id = 1 AND deleted_at IS NULL
ORDER BY created_at DESC LIMIT 15;

SELECT * FROM orders
WHERE organization_id = 1 AND deleted_at IS NULL
ORDER BY created_at DESC, id DESC LIMIT 5;

SELECT * FROM purchase_orders
WHERE organization_id = 1 AND deleted_at IS NULL
ORDER BY order_date DESC, id DESC LIMIT 15;

SELECT * FROM return_order_items WHERE return_order_id IN (2491,2492,2493,2494,2495,2496,2497,2498,2499,2500);
SELECT * FROM stock_transfer_items WHERE stock_transfer_id IN (2491,2492,2493,2494,2495,2496,2497,2498,2499,2500);
SELECT * FROM inter_company_transfer_lines WHERE inter_company_transfer_id = 2500;
SELECT * FROM inter_company_transfer_serials WHERE inter_company_transfer_line_id = 2500;
SELECT * FROM inter_company_transfer_batches WHERE inter_company_transfer_line_id = 2500;
```

The list probes isolate the base-table filtering/sort; application relation
loads, computed stock projections, pagination counts and rendering are not
included in these timings. Deep offset pagination still requires reading
earlier matching rows.

## Remaining candidates, ranked

These require workload evidence before adding more write overhead:

1. **Customer order history and warehouse order lists.** Evaluate
   `(organization_id, customer_id, order_date, id)` and
   `(organization_id, warehouse_id, order_date, id)` on `orders` if those filters
   dominate. `CustomerController::show()` loads all customer orders; pagination
   is also needed if a customer has a large history. MySQL's single-column
   foreign-key indexes help filtering, but do not supply tenant/date ordering.
2. **User warehouse membership.** PostgreSQL has only
   `(warehouse_id, user_id)` on `warehouse_user`. Measure the reverse lookup
   `(user_id, warehouse_id)` for users belonging to many warehouses or a large
   shared membership table.
3. **Frequently used status/supplier filters.** Measure tenant + filter + date
   composites for orders and purchase orders. Existing status/source indexes
   already narrow candidates; adding every combination is unnecessary. Prefer
   observed slow queries and filter selectivity over speculative indexes.
4. **Remaining PostgreSQL foreign-key lookups and hard-delete paths.** Of 203
   foreign-key columns inspected, 106 originally lacked a leading B-tree on
   PostgreSQL; five are fixed here, leaving 101. This is an inventory, not a
   recommendation to add 101 indexes. Many normal reads already use tenant-first
   composites, e.g. `order_payments(organization_id, order_id)` and
   `stock_audit_counts(organization_id, stock_audit_id)`. Profile unscoped parent
   deletes, nullable user references and high-volume relation loads separately.

### Queries that need more than a conventional index

- **Contains search (`%term%`).** PostgreSQL already has ten `pg_trgm` GIN
  indexes. Preserve those; a plain B-tree cannot replace them for arbitrary
  contains search. Variant SKU/barcode, order customer-email/external fields
  and JSON channel references are not all covered by those GIN indexes. The
  broad OR groups can still require scans. MySQL needs a deliberate prefix,
  full-text or search-service strategy; adding a B-tree on each searched string
  will not fix leading-wildcard matching, and FULLTEXT changes token/substring
  semantics. See [PostgreSQL trigram support](https://www.postgresql.org/docs/16/pgtrgm.html)
  and [MySQL range optimization](https://dev.mysql.com/doc/refman/8.0/en/range-optimization.html).
- **Date-wrapped stock movement filters.** `StockAdjustmentController::index()`
  and the stock movement report use `whereDate(created_at, ...)`. Rewrite these
  as raw timestamp bounds with the existing inclusive-day semantics before
  considering another index. Preserve timezone behavior in dedicated tests.
- **Low-stock and stock-value ranking.** These depend on effective variant and
  warehouse quantities, column comparisons and computed values. A simple index
  on `products.stock` does not index that result. Existing product/variant/bin
  lookup indexes help the subqueries; further work needs aggregate/query design
  and its own correctness tests.

## Validation and rollout

- Fresh application migrations completed on both database engines.
- Both new migrations rolled back and reapplied against the populated fixture;
  row counts and ID sums across nine affected tables were preserved.
- A repeated SKU within one tenant remained rejected by the SKU unique
  constraint, while reusing that SKU in a different tenant remained allowed,
  before and after the migration round trip.
- Each of the five relationships had exactly one usable leading index after
  reapply; MySQL retained its original foreign-key index names.
- Related application tests cover products, dashboard, purchase-order scope,
  returns, stock transfers and tracked inter-company transfers: **153 tests /
  882 assertions passed on each of SQLite, MySQL and PostgreSQL**. Each run
  reported the same 12 pre-existing PHPUnit deprecations.

Deploy with the normal `php artisan migrate --force` process. No production
database was changed by this audit. These use ordinary portable index DDL;
index construction consumes resources and can block writes, particularly on
PostgreSQL. Schedule a maintenance window for large installations, or adapt
the rollout to engine-specific online/concurrent DDL after reviewing table
sizes and privileges. Do not blindly copy the synthetic timing ratios into
capacity estimates.
