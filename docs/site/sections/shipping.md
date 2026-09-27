Inventoros ships sales orders in one or more shipments. Each shipment records which order lines (and how many units of each) went in the box, the carrier and tracking number, the label, the cost, and where it is now. You can enter tracking by hand for labels bought elsewhere, or connect EasyPost to compare rates and buy labels for USPS, UPS, FedEx, Canada Post, DHL and more from the order page.

### How shipments move an order

Stock leaves inventory when the order is created. Shipping never changes stock; it records what physically went out, and moves the order through its normal statuses (so the usual order notifications and `order.status_changed` webhooks fire):

| What happened | Order status |
|---|---|
| Some units have shipped, others have not | `pending` becomes `processing` |
| Every unit is in a shipment that has left | `shipped` |
| Every shipment that left has been delivered | `delivered` |

There is no separate "partially shipped" order status. `processing` already means "in fulfilment", and the order status is matched by filters, validation and integrations across the web app, REST, GraphQL and MCP, so a new value would break them. The order page shows exactly what is left to ship per line.

Shipment statuses: `pending` (lines allocated), `label_created` (a label is bought and can still be voided), `shipped`, `in_transit`, `delivered`, `exception` (returned, failed or lost) and `cancelled` (voided or abandoned before leaving; its units can be shipped again).

Once any of an order's shipments has left the warehouse, the order can no longer be cancelled or deleted, and its line items cannot be changed while it has open shipments. This stops Inventoros from restocking units that are already with the carrier.

### Setting up EasyPost

1. Create an account at [easypost.com](https://www.easypost.com) and connect the carrier accounts you want to use.
2. In Inventoros, open **Settings > Shipping** (needs `manage_organization`).
3. Tick **Use EasyPost to buy labels** and paste your **test API key** (`EZTK...`) and **production API key** (`EZAK...`). Keys are encrypted at rest and never shown again; leave a field blank to keep the stored key.
4. Leave **Test mode** on while you try it out. Test labels are free and never ship. Turn it off to buy real labels with the production key.
5. Set the **default ship-from warehouse**, and optionally a **ship-from address** (otherwise the warehouse's address is used) and a **default parcel** (weight in ounces, dimensions in inches, as EasyPost expects).
6. Optionally tick **Email customers their tracking details by default**.

#### Tracking webhooks

Tracking updates arrive in two ways:

- **Webhook (recommended).** Copy the **Webhook URL** from Settings > Shipping (`https://your-host/webhooks/easypost/{token}`), add it as a webhook in the EasyPost dashboard, and give it a webhook secret. Enter the same secret in Settings > Shipping. Every request must carry a valid `X-Hmac-Signature` (HMAC-SHA256 of the body with that secret); anything else is rejected with `401`, and events without a configured secret are always rejected. **Regenerate URL** issues a new token if the URL leaks.
- **Polling.** The scheduled `shipping:track` command runs every 30 minutes (make sure the Laravel scheduler is running). It checks shipped and in-transit EasyPost shipments that have not been checked in the last hour, oldest first, and makes at most 30 carrier calls per organization per minute. Run it by hand with `php artisan shipping:track --limit=200 --stale=60 --per-minute=30`.

When the carrier reports delivery, the shipment and (once every shipment is delivered) the order are marked delivered.

### Shipping an order

On the order page, the **Shipments** card lists every shipment with its status, tracking link and label. Click **Ship** (needs `create_shipments`):

1. Choose the quantity of each line to put in the box. It defaults to everything not yet shipped.
2. Choose the ship-from warehouse and the parcel weight and dimensions.
3. Then either:
   - **Buy a label with EasyPost**: check the ship-to address (prefilled from the customer's shipping address), click **Create shipment and get rates**, pick a rate, then **Buy label**. Download or print the label PDF from the dialog or the Shipments card.
   - **Enter tracking manually**: type the carrier, service, tracking number and cost. The tracking link is built automatically for USPS, UPS, FedEx, DHL, Canada Post and Purolator when you leave it blank. Tick **Mark as shipped now** if it has already gone.
4. Tick **Email tracking details** to send the customer a tracking email when the shipment leaves.

From the Shipments card you can **Mark shipped** a pending or labelled shipment, **Void label** on a bought label that has not shipped (EasyPost requests a refund from the carrier; if the refund is refused nothing changes), or **Cancel shipment** for a manual one.

A bought label is saved (tracking number, label link and EasyPost's full response) before the PDF is downloaded, so a paid label is never lost. If the download fails, the label is fetched again the next time you open it. Label files are stored on the private disk and are only served to users with `view_shipments`.

### Customer emails

The shipment email goes to the order's customer email once, when the shipment first leaves (marked shipped, or the carrier reports it moving). It lists the carrier, tracking number, the items in the box and a tracking link, has a plain-text part, is queued, and is sent with your organization's email settings and name.

### Permissions

| Permission | Allows |
|---|---|
| `view_shipments` | See shipments on the order page, download labels, list shipments over REST and MCP |
| `create_shipments` | Create shipments, fetch rates, buy and void labels, mark shipped |
| `manage_organization` | Settings > Shipping |

Administrators have both. Upgrading grants both to the Manager role and the Order Processor and Warehouse Staff templates, and `view_shipments` to the Read-Only Auditor template.

### REST API

All paths are relative to `/api/v1` and need a Sanctum token.

| Method and path | Permission | Purpose |
|---|---|---|
| `GET /shipments` | `view_shipments` | Paginated shipments; filter by `status`, `order_id`, `carrier` |
| `GET /shipments/{id}` | `view_shipments` | One shipment |
| `GET /orders/{id}/shipments` | `view_shipments` | An order's shipments |
| `POST /orders/{id}/shipments` | `create_shipments` | Create a shipment with manual tracking |
| `POST /shipments/{id}/ship` | `create_shipments` | Mark a shipment shipped |

```bash
curl -X POST "${APP_URL}/api/v1/orders/42/shipments" \
  -H "Authorization: Bearer ${TOKEN}" -H 'Content-Type: application/json' \
  -d '{"carrier_name":"UPS","tracking_number":"1Z999AA10123456784","items":[{"order_item_id":101,"quantity":2}],"mark_shipped":true}'
```

Omit `items` to ship every unit not yet in a shipment. Refusals (over-shipping, a cancelled order) return `422` with `"error": "shipping_error"` and a readable `message`. Buying EasyPost labels is done from the order page, where rates can be compared.

### MCP tools

- `list_shipments` (`view_shipments`). Paginated shipments, optionally for one order or status.
- `create_shipment` (`create_shipments`). Record a shipment with manual tracking, optionally marking it shipped. Destructive; confirm first.

### Webhook events

Subscribe to these in **Settings > Webhooks**:

- `shipment.created`: a shipment was created for an order.
- `shipment.delivered`: the carrier reported a shipment delivered.

Both payloads carry `data.shipment` (carrier, service, tracking number and link, status, cost, lines) and `data.order` (`id`, `order_number`, `status`). Order status changes caused by shipments also send the usual `order.status_changed` event.
