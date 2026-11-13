<p align="center">
  <a href="https://inventoros.com"><img src="public/images/brand/inventoros_icon_transparent_512.png" alt="Inventoros" width="88" height="88"></a>
</p>

<h1 align="center">Inventoros</h1>

<p align="center">
  Inventory management for the rest of us.<br>
  Open-source inventory and warehouse management you host yourself: stock, orders, purchasing and shipping in one place.
</p>

<p align="center">
  <a href="https://inventoros.com"><b>Website</b></a> ·
  <a href="https://inventoros.com/docs"><b>Docs</b></a> ·
  <a href="https://inventoros.com/docs/getting-started"><b>Install</b></a> ·
  <a href="CHANGELOG.md"><b>Changelog</b></a>
</p>

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="screenshots/dashboard.png">
  <img src="screenshots/dashboard-light.png" alt="The Inventoros dashboard with stock value, low-stock alerts, recent orders and purchase orders" width="100%">
</picture>

## What it does

<table>
  <tr>
    <td width="40%">
      <h3>Restock before you run out</h3>
      Minimum levels flag low stock on the dashboard. Raise a purchase order, receive it into the right bin, and the alert clears.
    </td>
    <td width="60%">
      <img src=".github/demo/receive-stock.gif" alt="A low-stock alert, the purchase order that restocks it, and a clear dashboard once it is received">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src=".github/demo/order-to-ship.gif" alt="A customer order created for a saved customer and marked shipped from the order list">
    </td>
    <td width="40%">
      <h3>From order to shipment</h3>
      Write up an order for a saved customer, take payment, buy a label and mark it shipped. Customers can follow it in their own portal.
    </td>
  </tr>
  <tr>
    <td width="40%">
      <h3>Every warehouse, down to the bin</h3>
      Warehouses, aisles, shelves and bins, with transfers between sites and staff limited to the warehouses they work in.
    </td>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="screenshots/products.png">
        <img src="screenshots/products-light.png" alt="The inventory list with stock levels per product">
      </picture>
    </td>
  </tr>
  <tr>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="screenshots/reports.png">
        <img src="screenshots/reports-light.png" alt="The reports page with valuation, stock movement and sales reports">
      </picture>
    </td>
    <td width="40%">
      <h3>Reports you'll actually open</h3>
      Valuation, stock movement, dead stock, turnover, margins and ABC analysis. Extend it with plugins, a REST and GraphQL API, and webhooks.
    </td>
  </tr>
</table>

## Quick start

On a Linux server, one command installs Docker, generates secrets and starts everything:

```bash
curl -fsSL https://inventoros.com/install.sh -o install.sh
less install.sh
sudo bash install.sh
```

Docker Compose, Coolify, cPanel and manual installs are in the [installation guides](https://inventoros.com/docs/getting-started). Upgrading from 1.0.x? Follow [UPGRADE.md](UPGRADE.md) once.

## Learn more

- [All features](docs/feature-overview.md) · [Screenshots](docs/screenshots.md)
- [Requirements, development setup and configuration](docs/development.md) · [cPanel deployment](CPANEL.md)
- [API guide](docs/api/README.md) · [Endpoint list](docs/api-endpoints.md) · [MCP server](docs/mcp/README.md)
- [Plugin development](docs/PLUGIN_DEVELOPMENT.md) · [External file storage](docs/external-storage.md)
- [Contributing](CONTRIBUTING.md) · [Community and support](docs/community.md) · [Security policy](SECURITY.md)

## License

Copyright (c) 2025-2026 Inventoros ([inventoros.com](https://inventoros.com)).

Inventoros is free, open-source software licensed under the [GNU Affero General Public License v3.0](LICENSE) (AGPL-3.0-only). If you run a modified version of Inventoros as a network service, the AGPL requires you to offer your users the source code of that modified version.

Releases up to and including v1.0.8 were published under the MIT license and remain available under those terms.
