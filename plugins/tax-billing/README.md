# Tax Billing

An optional, organization-scoped invoice plugin. Activate **Tax Billing** in
Settings → Plugins, grant the **Manage tax billing profiles** permission to the
people configuring it, then open **Tax billing** in the sidebar. Grant **Issue
tax invoices** to the people who approve invoice details. Reading invoices uses
the existing **View orders** permission and warehouse access rules.

## Countries and tax profiles

Choose **Custom country profile** for any country. Enter its two-letter country
code, supplier registration details, the local tax identifier label (VAT number,
GSTIN, TIN, etc.) and one or more additive tax components. For example, a business
can configure VAT, HST, or GST and PST using its applicable rates. No country or
rate is assumed. The organization retains its existing currency settings.

**India GST: domestic supplies** adds GSTIN shape validation, supplier state-code
matching, HSN/SAC classification and a place-of-supply state code. The configured
total GST rate is split into CGST and SGST (or UTGST) for the same state; IGST is
used for a different state. Customer GSTIN uses the existing **Tax ID/VAT** field
in Business Details. Leave it blank for an unregistered customer. The additional
customer field stores a default place-of-supply region, which the issuer reviews
for each invoice. GSTIN validation checks format, not registration or checksum.

These profiles describe a single tax treatment per order. They do not choose
rates or determine tax liability. Mixed-rate orders, compound taxes, exemptions,
India exports/SEZ/reverse charge/cess, tax returns and government e-invoice
acknowledgements are not automated. Configurable presentation for a country is
not a certification of compliance with every requirement of that jurisdiction.

## Issue an invoice

Open an order and select **Tax invoice**. Review the place of supply, HSN/SAC
codes and units, then confirm and issue. The order's recorded tax must match the
configured rate on its net taxable value (within one cent for rounding).
Shipping can be included in that base. Inconsistent totals, cancelled orders,
and orders awaiting approval cannot be issued. Issuing does not change stock,
payments or the order's tax.

The PDF groups variant quantities by the configured size option. Different
colours and other non-size options, prices, HSN/SAC codes and units remain
separate. Archived variants retain their option details. Line and order
discounts are reflected in net amounts; tax components and discount allocations
use exact decimal arithmetic and reconcile to the recorded cents.

Issuing reuses the core invoice number and saves the issuer, customer, lines,
tax components and totals on the order as an immutable presentation snapshot.
Repeated requests return the same invoice. Later profile/customer/order edits
do not rewrite it. Existing download, email and customer portal invoice routes
use the saved document. If the plugin is inactive or unavailable, those routes
refuse to substitute a different document; reactivate the plugin to retrieve
the issued invoice. There is no automatic credit-note or replacement-invoice
workflow in this version.

## Build and verification

The prebuilt `dist/` bundle is included. To rebuild from the repository root:

```sh
npm ci
npm run build:plugin:tax-billing
php artisan test tests/Feature/Plugins/TaxBillingTest.php
```

No additional database migration is needed. Settings live in the organization's
settings and issued snapshots in order metadata. Customer fields use the shared
customer-form extension hooks. The renderer uses `order_invoice_document` so
staff and customer invoice delivery follows the same saved snapshot.
