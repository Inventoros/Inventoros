# Large stock audits

The audit page loads 25 lines at a time, with options for 50 or 100. Search product names, variant titles, SKUs and barcodes, or filter uncounted, counted, variance and review lines. Progress, variance/review totals and completion confirmation always cover the entire audit, regardless of the current page or filter.

Barcode lookup searches the entire audit on the server and returns at most two matches. Multiple matching bins or variants require a location selection or entering the count on the appropriate line; a scan never chooses the first ambiguous match. Product QR links and location QR labels remain supported. Camera/manual-modal and keyboard scanners use the same audit-scoped lookup. A successful increment patches its acknowledged line and whole-audit summary without reloading the catalogue.

Entering a count for a scanned line outside the current page opens its unfiltered page. Offline drafts remain scoped to user, organization and audit. Sync reads an off-page draft by its stored item ID and submits its original revision, preserving stale drafts for review rather than dropping them because they are not visible. Changing organization invalidates queued scans from the earlier scope.

Completion retains its existing transaction, row locks, plugin veto, review gate and ledger behavior. It locks product IDs in bounded pages and streams audit lines with their product/variant models instead of retaining the full catalogue in memory. Plugins can still explicitly load the audit's relations when they need them.

REST clients can use `GET /api/v1/stock-audits/{audit}/items` with `per_page` (25, 50 or 100), `page`, `search` and `item_status` (`all`, `pending`, `counted`, `variance`, `review`). Its paginator includes an unfiltered `summary`. `GET .../items/{item}` returns one line and summary; `GET .../lookup?code=...` matches codes across the audit. All endpoints enforce audit organization, warehouse and read permissions.

The legacy audit detail response still includes all items by default. Send `include_items=0` for metadata and summary only; create/start/complete responses also accept that option to avoid a large response. These opt-outs preserve existing API clients while new clients use paginated lines.
