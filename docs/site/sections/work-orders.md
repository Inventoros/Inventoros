A work order builds an assembly product from its bill of materials (BOM): it consumes the components and adds the finished units to stock. Work orders live under **Workspace > Work Orders** and need the `manage_stock` permission. The same rules apply on the web, over the REST API and through the MCP server.

## Lifecycle

| Status | What it means | Stock |
|---|---|---|
| `draft` | Created from the assembly's BOM. Can be deleted. | Nothing moves |
| `in_progress` | Started. Every component was available when it started. | Nothing moves |
| `completed` | Components consumed and output received, in one step. | Moves once |
| `cancelled` | Stopped before completion. Can be deleted. | Nothing moves |

Stock only moves when a work order is completed. Starting checks that every component can be covered (its stock and, for tracked products, its available serials or batch quantity) but reserves nothing, so the check runs again when you complete. A completed work order cannot be cancelled: its stock movements are final, and an assembly built by mistake is taken apart with stock adjustments.

Completion is all or nothing: if any component falls short, or a chosen serial or batch is no longer usable, nothing moves and the work order stays in progress. A request based on an out-of-date page (the order was completed or cancelled in another tab) is refused rather than moving stock twice.

## Partial production

**Quantity produced** defaults to the planned quantity and can be lower. Components are consumed in proportion to what was produced (rounded up per line), so a partial completion does not use up materials for units that were never made. The work order is then completed; start a new one for the rest.

## Variants

When the assembly or a component is stocked by variant (it has variants, each with its own stock), the work order needs to know which one:

- **Variant to produce** on the create form picks the variant the finished units go into.
- The components table asks for the variant of every component stocked by variant.

A variant line consumes and produces the variant's stock and writes a ledger row for that variant; the parent product's total and its location bins are left alone, as with orders. Variant stock carries no serials or batches, so serial and batch tracking applies only to products that are not stocked by variant. If a product starts being stocked by variant after the work order was created, completing it is refused; create a new work order that names the variant.

## Serial-tracked components and output

For a serial-tracked component, the completion form lists the available serials. Tick exactly as many as the line consumes, or tick none to use the oldest available serials. The serials used are marked **Consumed**, a status that the serial list cannot change, so a unit built into an assembly cannot be put back on the shelf by hand.

For a serial-tracked assembly, enter one serial number per unit produced (one per line). Each becomes a new, available serial of the assembly. Numbers must be unique in the organization; a number already in use refuses the completion.

## Batch-tracked components and output

For a batch-tracked component, the completion form lists its batches in the order automatic allocation would use them (earliest expiry first, batches without an expiry last). Enter how many units to take from each batch, totalling what the line consumes, or leave them blank to allocate automatically. A plugin can hold units of a batch back (a quarantined lot) with the `batch_allocatable_quantity` filter; held units are never consumed.

For a batch-tracked assembly, enter a batch number and an optional expiry date. An existing batch of the assembly with that number is topped up (a different expiry date is refused, as it would be a different lot); otherwise a new batch is created. Leave the number blank to generate one (`BATCH-YYYYMMDD-0001`).

## Kits

A virtual kit has no stock of its own: its availability comes from its components. When an assembly's BOM includes a kit, the work order lists the kit's components instead (multiplied through, including kits inside kits), each marked with the kit it came from, and consumes those. A kit can never be the output of a work order; only assembly products can be produced.

If a component is turned into a kit after the work order was created, completing it is refused; create a new work order so the kit's components are listed.

## What is recorded

Every completion writes `assembly_consumption` and `assembly_production` stock adjustments that reference the work order, with the variant when there is one. Bins move with the totals: consumption draws from the component's bins, and the output is booked into the assembly's primary location. Alongside the ledger:

- each component line keeps the variant it used and the kit it came from;
- `work_order_allocations` records each serial and batch quantity a line consumed;
- `work_order_outputs` records what the work order produced: the product, the variant, each serial or the batch.

The work order page shows the serials and batches each line used and what was produced. Over the REST API, `GET /api/v1/work-orders/{id}` returns them as `items.*.allocations` and `outputs`.

## REST API

The endpoints are listed in the REST API section. Creating takes `product_variant_id` and `component_variants` (`[{ "product_id": 12, "product_variant_id": 40 }]`). Completing takes `quantity_produced`, optional `allocations` per component line (`[{ "work_order_item_id": 7, "serial_ids": [101, 102] }]` or `"batches": [{ "product_batch_id": 3, "quantity": 4 }]`) and, for a tracked assembly, `output` (`{ "serial_numbers": ["A-1", "A-2"] }` or `{ "batch_number": "LOT-7", "expiry_date": "2032-05-01" }`). Refusals answer `422` with an `error` code: `insufficient_stock`, `invalid_status`, `variant_required`, `invalid_variant`, `unsupported_kit`, `invalid_serial`, `serial_unavailable`, `serial_count_mismatch`, `serial_taken`, `invalid_batch`, `batch_quantity_mismatch`, `batch_expiry_mismatch`, `invalid_allocation`, `invalid_output` or `invalid_quantity`.
