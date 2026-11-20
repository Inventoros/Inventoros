Inventoros can accept a sales order for more than you have on hand, record the shortfall as a backorder, and hold the order from shipping until the stock arrives. The available units are committed as usual; only the shortfall waits. This is off until you turn it on, so nothing changes for an organization that does not use it.

## Turning backorders on

Backordering has a master switch and a per-product toggle, and a line can be backordered only when both are on.

1. Open **Settings > Organization > Backorders** (needs `manage_organization`) and tick **Allow backorders**. Optionally tick **Enable for new products by default** to pre-select the product toggle on new products.
2. On a product's form, tick **Allow backorder**. The toggle appears only while the organization switch is on. Over the REST API, set `allow_backorder` when creating or updating a product.

## How a backorder is created

When an order line is short, Inventoros fulfils what is available and records the rest as backordered. The product's stock is drawn down for the fulfilled units only; the backordered units never leave stock until they are reconciled. The order is stamped as backordered and shown with a banner, and each short line shows its split (for example "3 fulfilled, 2 on backorder"). Orders created through the web app, REST, GraphQL and MCP all follow the same rule.

If a product is not opted in, or the master switch is off, a short line is refused exactly as before.

## Shipping is held until stock arrives

An order with any backordered units cannot be shipped: creating a shipment for it, or setting its status to shipped or delivered by any route (the order form, REST, GraphQL or MCP), is refused until every line is fulfilled. This keeps an order shipping together rather than in pieces. The hold is driven by the order's own backordered state, not by the master switch, so turning the switch off later never lets a backordered order ship early.

## Fulfilling a backorder

When stock comes back, Inventoros reconciles outstanding backorders for that product, oldest order first, up to what is now available:

- Receiving a purchase order, or any stock increase recorded through the ledger, reconciles automatically.
- The **Fulfil backorder** button on the order page (needs `manage_stock`) reconciles that one order against current stock.
- A scheduled sweep (`inventory:reconcile-backorders`) catches anything a direct path missed.

Reconciliation moves stock the same audited way order creation does: it draws the order's warehouse bins first, allocates serial and batch units, and writes an `order_fulfillment` ledger row. When an order's last backordered unit clears, its hold is lifted and it can ship.

## Cancelling, rejecting, deleting and editing

Only the fulfilled units ever left stock, so cancelling, rejecting or deleting a backordered order returns only those to inventory and clears the backorder. Editing an order recomputes the split against current stock, so growing a line past what is available backorders the difference, and shrinking it back into stock clears the backorder. If backorders have since been turned off, for the organization or the product, an edit keeps the units already on backorder but cannot add more.

## Preorders

A product that is not out yet can take orders before its release date. Set **Release date** on the product's form, or send `release_date` (`YYYY-MM-DD`) over the REST API. Until that date the product is a preorder:

- While the organization's **Allow backorders** switch is on, it can be ordered past its stock even if **Allow backorder** is not ticked on the product. The shortfall goes on backorder and is fulfilled the same way as any other backorder when stock arrives.
- An order that includes it cannot ship before the release date, even if the stock has already arrived. Creating a shipment, or setting the status to shipped or delivered by any route, is refused until then. The order page shows the date in a banner and on each preorder line, and the REST order shows it as `preorder_release_date`.

The product is released at the start of its release date, after which it behaves like any other product. Clearing the date releases it straight away. Products return `release_date` and `is_preorder` over the REST API.

## What is not covered yet

This release does not draft a supplier purchase order for the shortfall automatically. The hold is all or nothing; there is no per-order "ship the available part now" override.
