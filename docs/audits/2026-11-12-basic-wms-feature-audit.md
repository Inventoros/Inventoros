# Basic warehouse feature audit

Date: 2026-11-12
Baseline: `316e45d7` (Inventoros 2.4.0, current `origin/main` when reviewed)

## Scope and outcome

Review ordinary inventory and single-order warehouse work, including the free
core and free official plugin boundaries.
Paid plugins, optimization, 3PL billing, forecasting, enterprise integrations and
automation hardware are outside this implementation.

Core already covers products and variants, multiple warehouses and bins,
stock adjustments and transfers, lot/serial tracking and basic FEFO, kits and
assembly, purchasing and receiving, cycle counts, orders and returns, customer
access, shipping and tracking, reports, APIs and MCP. Basic scanner workflows
also have a free official plugin. These capabilities should remain included.

The review covered feature availability, shared service boundaries, access
control, query behavior, maintainability, failure cases and existing regression
coverage. It identified two missing basic documents and two confirmed workflow
defects addressed in this change. This is a code-level audit of the supported
inventory and fulfillment workflows.

## Findings addressed

| Priority | Finding | Evidence before this change | Result |
| --- | --- | --- | --- |
| P1 | No printable single-order pick list | `DocumentPdfService` rendered invoices and supplier POs only; sales routes and order actions had no pick document | Free order pick-list PDF and order-page action |
| P1 | No price-free packing slip | Core had no packing-slip template or route; ordinary shipment users needed invoice PDFs or a specialist plugin | Free packing slip per shipment, including manual and partial shipments |
| P1 | Existing shipment actions bypassed warehouse assignments | Shipment creation checked `WarehouseAccessService`, but existing-shipment rates, label purchase, ship, cancellation and web label download checked organization only | Matching warehouse authorization before carrier calls, file access or mutation |
| P2 | Deleted products could be reported as successfully received | `PurchaseOrderItem::receive()` could return null, while `PurchaseOrderService` still incremented its successful line count | Clear validation failure and whole-delivery rollback for unavailable products or variants |

## Fulfillment document behavior

- Pick lists are available for stock-committed pending/processing orders whose
  approval is granted or not required. Historical imports recorded without stock
  movement, cancelled orders, and already shipped/delivered orders do not create
  new picking work.
- Pick quantities are ordered quantities minus quantities in shipments that have
  physically left: shipped, in transit, delivered or exception. Pending,
  label-created and cancelled parcels do not reduce the checklist. Zero lines
  are omitted and an empty list is refused.
- The list uses stored order-line names and SKUs. It does not invent allocated
  bins or routes: core does not persist which source bins an order consumed.
  Variant SKUs remain available after product or variant changes.
- Packing slips contain the exact parcel lines and the shipment's saved address.
  They work before dispatch and can be reprinted after dispatch. Cancelled
  shipments cannot produce a slip.
- Document view data is an explicit whitelist. Prices, costs, balances, payments,
  internal notes, arbitrary metadata, address email and phone are omitted.
  Text is escaped. The invoice renderer is not called.
- Routes require the corresponding order/shipment view permissions and check
  tenant and warehouse access. Packing slips check both the order warehouse and
  the parcel's ship-from warehouse.
- Printing does not assign invoice numbers, change stock, update shipment/order
  state, contact carriers, or send email. UI action labels cover all 14 locales;
  PDF labels follow the existing server-side document language conventions.

## Follow-up basic capability

Manual selection of a receiving bin is a useful separate addition (P2, medium
effort). `PurchaseOrders/Receive.vue` currently accepts quantities only. The
existing PO-line `receiving_location_id` primitive supports generated variant
replenishment receipts, but the ordinary receiving form does not let staff
choose it. A complete addition needs consistent active-bin, organization and
warehouse validation across web, REST, GraphQL and MCP. It is not included in
this focused document/workflow change and should not require a paid plugin.

The existing consume-at-order-creation inventory model was reviewed alongside
`OrderWarehouseBinConsumeTest`. It is deliberate behavior, not reclassified here
as a newly introduced defect. Recording exact allocations or redesigning physical
pick confirmation needs a separate design and migration review.

## Verification

- `FulfillmentDocumentsTest`: partial quantities, every shipment status,
  unpickable/historical orders, stored product/address values, escaped text,
  omitted private data, read-only PDF responses, permissions and tenant/warehouse
  boundaries.
- `ShipmentWarehouseAccessTest`: blocked actions have no carrier, file or state
  effects; own-warehouse, admin, all-warehouse and legacy unassigned-user behavior
  remain available.
- `PurchaseOrderDeletedProductReceivingTest`: web/REST/GraphQL/MCP validation,
  deleted variants, mixed-delivery rollback and restore/retry.
- Existing receiving and shipping regressions, JavaScript localization and UI
  tests, production build, PHP formatting, and complete default PHP suite.
- Final results: 4,076 PHP tests passed, 7 skipped (29,172 assertions); all 226
  JavaScript tests passed. Production build, Pint and whitespace checks passed.
- Rendered one-page pick list and packing slip plus a 75-line, multi-page pick
  list. Checked wrapping, repeated table headers, row pagination and page numbers.

