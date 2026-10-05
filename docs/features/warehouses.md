# Warehouses

Warehouses group storage locations. Stock lives in per-location bins (`product_location_stocks`), so a product's on-hand in a warehouse is the sum of its bins at that warehouse's locations. Products themselves are organization-wide catalogue items.

## Contents

- [Warehouse access](#warehouse-access)
- [Assigning users and the default warehouse](#assigning-users-and-the-default-warehouse)
- [Per-warehouse reorder points](#per-warehouse-reorder-points)
- [Capacity and utilisation](#capacity-and-utilisation)
- [Fulfilment priority](#fulfilment-priority)

## Warehouse access

Who can see and act on which warehouse is decided in one place, `App\Services\WarehouseAccessService`, and every surface (web, REST, GraphQL, MCP) asks it.

| User | Warehouses they can access |
|---|---|
| Admin, or any role with `access_all_warehouses` | All of them, whatever their assignments |
| Assigned to one or more warehouses | Only those warehouses |
| No assignments, organization setting off (the default) | All of them (the behaviour before assignments were enforced) |
| No assignments, organization setting on | None |

The organization setting is "Restrict users to their assigned warehouses" on the Warehouses page (`manage_warehouse_users`). It is off by default so upgrading never locks anyone out; only an explicit assignment narrows what a user sees.

`access_all_warehouses` is granted to the Administrator role (all permissions), the Manager system role, and the Inventory Manager and Read-Only Auditor permission set templates. An upgrade migration adds it to existing copies of those roles.

For a restricted user:

- **Locations**: lists show only locations in their warehouses; creating, editing or deleting a location outside them is refused. A location with no warehouse is outside every assignment.
- **Stock adjustments**: must name a location in their warehouses (an adjustment without a location changes the organization-wide total). History shows only adjustments made at their locations. GraphQL `createStockAdjustment` and the MCP `adjust_stock` tool accept `location_id` for this.
- **Transfers**: visible and actionable when either end is in their warehouses; stock can only be sent from a location they can access (sending to another warehouse is allowed).
- **Stock audits**: only audits of a location in their warehouses; whole-organization audits (no location) are hidden and cannot be created.
- **Purchase order receiving**: goods are booked into each product's primary location, so a line can only be received when that location is in their warehouses.
- **Returns (RMA)**: a return belongs to the warehouses its goods go back to, which is the primary location of each line's product (where receiving the return restocks). A return is visible and can be approved, completed or rejected when any of its lines comes back to their warehouses; raising a return, or receiving one, needs every restocked line to land in their warehouses. The originating order is not used, because orders carry no location.
- **Products**: the catalogue stays visible, but the per-location stock breakdown and per-warehouse levels show only their warehouses.

Denied requests return 403 (web and REST), an error (GraphQL), or a tool error (MCP).

## Assigning users and the default warehouse

The warehouse edit page has a **User access** section (`manage_warehouse_users`) that saves through `POST /warehouses/{warehouse}/users`. Users who are never restricted (admins, `access_all_warehouses`) are labelled "All warehouses". Saving an empty list removes every assignment.

The warehouse page and the warehouse list have **Make default** (`edit_warehouses`). The default warehouse is preselected for new orders.

## Per-warehouse reorder points

The product page's **Stock by warehouse** table shows on-hand per warehouse and lets `edit_products` users set min stock, reorder point, reorder quantity and max stock per warehouse (`warehouse_reorder_points`).

- A warehouse with a row is tracked on its own: its on-hand is compared with its thresholds. A blank field inherits the product-level value.
- A warehouse with no row is not tracked separately; the product-level thresholds keep applying to the product's total stock.
- `Product::lowStock()` and `Product::needsReorder()` match a product that is low or due in total **or** in any warehouse with its own thresholds, so the low-stock filter and report, `inventory:check-reorder-points`, and the dashboard's reorder suggestions all pick it up.
- `ReorderService::suggestedQuantity()` orders what the short warehouses need (each warehouse's reorder quantity, or the gap up to its max stock), still never below the supplier's minimum order. `ReorderService::warehouseShortfalls()` lists them.
- When a bin change takes a warehouse across its own minimum, stock managers who can access that warehouse get an in-app "Low stock in {warehouse}" alert (type `warehouse_low_stock`, hook `warehouse_low_stock_alert`). The product-level low-stock alert and email are unchanged.

## Capacity and utilisation

Warehouses and locations have an optional capacity in units. The warehouse page shows utilisation as on-hand divided by the warehouse's capacity, or by the sum of its locations' capacities when only those are set. Capacity is informational: nothing is refused for being over it.

## Fulfilment priority

When stock is consumed (orders, work orders, reconciliation), `ProductLocationStockService::consume()` drains bins in the highest-priority warehouse first (higher `priority` wins; a location with no warehouse counts as 0). Within equal priority the product's primary location goes first, then the fullest bins. With every warehouse at the same priority the order is exactly what it was before.

An order that names a warehouse (`orders.warehouse_id`) draws its lines from that warehouse's bins first, in the same order among themselves (primary location, then the fullest bin). Only when they run out does the rest come from the other bins in the priority order above, so an order never fails for being short in its own warehouse. An order with no warehouse, or one whose warehouse holds none of the product, drains purely by priority. Cancelling or editing an order still returns its units to the product's primary location.
