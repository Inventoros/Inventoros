# Receiving purchase orders into bins

Open a sent or partially received purchase order and choose **Receive**. Enter
the quantity arriving on each line and optionally select a **Receiving bin**.
This feature is included in core.

**Use existing destination** keeps the PO line's planned receiving location, or
the product's primary location when the line has no plan. If there is no location,
the existing unassigned-stock behavior applies for unrestricted receivers.

A selected bin applies only to that receipt. It does not change the product's
primary location or the PO line's planned location. For partial deliveries, the
next receipt still defaults to the original destination. The stock ledger records
the location actually used.

Only active bins in your organization are offered. Warehouse assignments apply,
and a bin in an inactive or deleted warehouse cannot receive stock. A planned
receipt can use a replacement bin in the same planned warehouse; it cannot be
redirected to another warehouse. Ordinary unplanned lines can use any accessible
active bin. If a planned bin is retired, another active bin in its warehouse can
be selected.

Invalid destinations or unavailable products/variants roll back the whole submitted
delivery. No earlier line is silently left booked when a later line fails.

## Integrations

Web, REST, GraphQL and MCP use the same receiving service. Each receipt item
accepts an optional `receiving_location_id`:

```json
{
  "items": [
    {"id": 12, "quantity_to_receive": 5, "receiving_location_id": 41}
  ]
}
```

Omit the field or pass `null` to use the existing destination. The ID must be a
positive integer; the actual bin and warehouse are validated when the receipt is
processed. A valid bin outside the receiver's warehouse assignments is refused.
Lines with a zero receipt quantity do not book stock or change their destination.

- REST: `POST /api/v1/purchase-orders/{id}/receive`.
- GraphQL: `receivePurchaseOrder`, with `receiving_location_id` on each
  `PurchaseOrderReceiveItemInput`.
- MCP: `receive_purchase_order`, with the same optional field in each item.
