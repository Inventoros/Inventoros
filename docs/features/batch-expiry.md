# Batch expiry

Open **Reports → Batch expiry** with `view_reports` and `view_products` to find lots that have units remaining and an expiry date. The report includes archived and inactive products so their remaining lots are still visible.

The default window shows expired lots and lots due within the next 30 days. Choose 7 or 90 days, expired only, or all expiry dates. Search by product name, SKU or batch number. Table headings sort the full matching result before pagination; totals cover all matching lots, not just the current page.

Dates follow the organization's timezone. A lot expiring today is **due today**, and becomes expired when that calendar day ends. The product page and batch API use the same rule. Missing or invalid legacy timezones fall back to UTC.

**Export CSV** keeps the applied filters and sort order. Exports are bounded by `REPORTS_MAX_ROWS` (10,000 by default); the page warns when more lots match. The download also carries `X-Report-Row-Limit` and `X-Report-Truncated` headers. Narrow the filters to reduce the export. Each row includes the organization's as-of day.

Quantities are organization-wide remaining lot units. Batches do not currently have a warehouse, location or variant ledger, so this report does not assign them to warehouses or infer a location from the product's primary bin. It does not change allocation, fulfilment or stock quantities.
