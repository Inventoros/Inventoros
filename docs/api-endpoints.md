# API

## API

The REST API is available at `/api/v1/` with Sanctum token authentication.

<details>
<summary>View all API endpoints</summary>

| Resource | Endpoints |
|----------|-----------|
| Products | `GET`, `POST`, `GET/{id}`, `PUT/{id}`, `DELETE/{id}` |
| Product Options | Nested under products: `GET`, `POST`, `PUT/{id}`, `DELETE/{id}` |
| Product Variants | Nested under products: `GET`, `POST`, `PUT/{id}`, `DELETE/{id}`, `POST/adjust-stock` |
| Product Components | Nested under products: `GET`, `POST`, `PUT/{id}`, `DELETE/{id}` |
| Batch Tracking | Nested under products: `GET`, `POST`, `GET/{id}` |
| Serial Tracking | Nested under products: `GET`, `POST`, `GET/{id}`, `PUT/{id}` |
| Categories | `GET`, `POST`, `GET/{id}`, `PUT/{id}`, `DELETE/{id}` |
| Locations | `GET`, `POST`, `GET/{id}`, `PUT/{id}`, `DELETE/{id}` |
| Warehouses | `GET`, `POST`, `GET/{id}`, `PUT/{id}`, `DELETE/{id}` |
| Orders | `GET`, `POST`, `GET/{id}`, `PUT/{id}`, `DELETE/{id}`, `POST/approve`, `POST/reject`, `POST/invoice/email` |
| Order Payments | Nested under orders: `GET/payments`, `POST/payments`, `POST/refunds`, `POST/payments/{id}/void` |
| Shipments | `GET`, `GET/{id}`, nested under orders: `GET`, `POST`; `POST/rates`, `POST/buy-label`, `POST/void-label`, `POST/ship` |
| Approvals | `GET`, `GET/mine`, `POST/{type}/{id}/approve`, `POST/{type}/{id}/reject` |
| Customers | `GET`, `POST`, `GET/{id}`, `PUT/{id}`, `DELETE/{id}`, `GET/{id}/orders` |
| Returns (RMA) | `GET`, `POST`, `GET/{id}`, `PATCH/{id}/items`, `POST/approve`, `POST/receive`, `POST/complete`, `POST/reject` |
| Stock Transfers | `GET`, `POST`, `GET/{id}`, `POST/ship`, `POST/complete`, `POST/cancel` |
| Stock Adjustments | `GET`, `POST`, `GET/{id}` |
| Stock Audits | `GET`, `POST`, `GET/{id}`, `POST/start`, `POST/items/{item}/count`, `POST/complete` |
| Suppliers | `GET`, `POST`, `GET/{id}`, `PUT/{id}`, `DELETE/{id}` |
| Purchase Orders | `GET`, `POST`, `GET/{id}`, `PUT/{id}`, `DELETE/{id}`, `POST/receive`, `POST/send`, `POST/cancel`, `POST/submit-for-approval` |
| Work Orders | `GET`, `POST`, `GET/{id}`, `DELETE/{id}`, `POST/start`, `POST/complete`, `POST/cancel` |
| Webhooks | `GET`, `POST`, `GET/{id}`, `PUT/{id}`, `DELETE/{id}`, `POST/regenerate-secret`, `GET/deliveries` |
| Users | `GET`, `POST`, `GET/{id}`, `PUT/{id}` |
| Saved Reports | `GET`, `POST`, `GET/{id}`, `PUT/{id}`, `DELETE/{id}`, `GET/{id}/export` |
| Permission Sets | `GET`, `POST`, `GET/{id}`, `PUT/{id}`, `DELETE/{id}`, `GET/categories` |
| Barcode Lookup | `GET/{code}` |

</details>

The **GraphQL API** is available at `/graphql` with Sanctum bearer token authentication. The [API guide](api/README.md) covers authentication, permissions, webhooks and GraphQL, and [docs/api/openapi.yaml](api/openapi.yaml) is the full spec. A running install also serves an interactive reference at `/docs/api` on its own domain; outside `local` it requires a signed-in user unless `API_DOCS_PUBLIC=true`. Guides are also published at [inventoros.com/docs](https://inventoros.com/docs).
