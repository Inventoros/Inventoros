# Security &amp; Reliability Audit — Inventoros app

**Date:** 2026-06-03
**Scope:** the multi-tenant inventory/WMS application (Laravel 13 + Inertia + Vue) — OWASP Top 10 + reliability / data-integrity.
**Method:** static review of multi-tenancy, auth/Sanctum, the plugin uploader, the in-app updater, webhooks, the GraphQL / MCP / REST surfaces, mass assignment, injection, validation, rate limiting, and the inventory/order mutation paths.

## Summary

The application is **well-hardened**. The dangerous surfaces (plugin-upload RCE, in-app updater, multi-tenancy, webhook SSRF, injection) have strong, verified controls — see **Verified safe** below. **No Critical issues were found.** The actionable items concentrate in two areas:

1. **Concurrency double-submit gaps** in the *secondary* stock-mutation paths (PO receiving, work orders, stock audits, returns). The core sales-order path is exemplary; these adjacent paths did not all inherit its lock-and-re-check pattern.
2. **GraphQL-vs-REST drift** — the GraphQL surface is in places less strict than its REST equivalent (cross-tenant `exists` rules, missing `max:` caps, and a cancel path that does not restock).

A small set of clearly-safe fixes were applied in **PR #136** (see *Applied*). Every item below marked **[REVIEW]** touches auth, multi-tenancy core, the plugin loader, the updater, payments, or concurrency logic and is intentionally left for human review rather than auto-changed.

## Applied (PR #136)

- `PluginService::loadPlugin` — `require_once` + `plugin_loaded` wrapped in `try/catch (\Throwable)` so a parse/fatal error in one active plugin can no longer take down every request at boot; non-JSON manifest is skipped.
- `PurchaseOrderItem::receive` — early-return when the line's product was deleted (was a fatal inside `StockAdjustment::adjust`).
- `UpdateOrderMutation::resolve` — null-guard `auth()->user()` before the permission check.

---

## Security findings (OWASP)

### High

- **[REVIEW] Plugin slug path containment** — `app/Http/Controllers/Admin/PluginController.php:88-150` + `app/Services/PluginService.php:170-201,403-429`. `activate`/`deactivate`/`destroy` take the raw `{plugin}` route segment as `$slug` and build filesystem paths via string concatenation; `deletePlugin()` calls `File::deleteDirectory($pluginsPath.'/'.$slug)` with no `basename()`/realpath containment (unlike the upload path, which is carefully guarded). A `manage_plugins` admin passing a slug such as `..%2f..%2fstorage` could direct directory deletion outside `/plugins`. Gated behind `permission:manage_plugins`, so this is a privilege → destructive-FS / load-arbitrary-path escalation within an admin-trust boundary, not anonymous. **Fix:** validate `$slug` against `^[a-z0-9._-]+$`, reject `/` `\` `..`, and `realpath()`-contain under `pluginsPath` before any delete/require.

### Medium

- **[REVIEW] GraphQL cross-tenant `exists` (IDOR)** — `app/GraphQL/Mutations/CreateProductMutation.php:92-97`, `app/GraphQL/Mutations/UpdateProductMutation.php:98-103`. `category_id` / `location_id` use `exists:product_categories,id` / `exists:product_locations,id` **not** scoped to the caller's organization. The REST equivalent (`app/Http/Requests/StoreProductRequest.php:36-37`) **does** scope them. A tenant can attach another org's category/location FK to their own product (the foreign name then surfaces via the relation). **Fix:** `Rule::exists(...)->where('organization_id', $user->organization_id)` to mirror REST.
- **[REVIEW] `UpdateController::check` missing `is_admin` guard** — `app/Http/Controllers/Admin/UpdateController.php:72-84`. Every sibling method (`update`, `backup`, `restore`, `deleteBackup`, `index`) checks `$user->is_admin`; `check()` relies only on route-level `permission:manage_organization`, letting a non-admin org-manager trigger outbound GitHub update-check traffic + version enumeration. **Fix:** add the same `is_admin` guard for consistency.
- **[SAFE — recommended follow-up] GraphQL missing `max:` caps** — `CreateProductMutation`, `UpdateProductMutation` (`description`), `UpdateSupplierMutation` (`address`), `CreateStockAdjustmentMutation` (`notes`) validate free text as `nullable|string` with no length cap; the REST Form Requests cap these. Unbounded authenticated input → storage/DoS/log bloat. **Fix:** add `max:5000` (or appropriate). Mechanical; left out of PR #136 only to keep that PR minimal.

### Low

- **[REVIEW] Backup-file validation** — `app/Http/Controllers/Admin/UpdateController.php:164-239`. `backup_file` is `required|string`, `basename()`-contained but no extension/allowlist check. **Fix:** validate against `^[A-Za-z0-9._-]+\.zip$` and confirm membership in `listBackups()` before use.
- **[REVIEW] `PublicHostGuard` fail-open** — `app/Support/PublicHostGuard.php:44-50`. On DNS failure the SSRF guard proceeds (documented CI/Docker resilience); a host returning empty DNS bypasses the private-IP check, and there is a small TOCTOU window vs. the connect. **Fix:** fail closed when `app.env === 'production'`, or pin + connect the resolved public IP.
- **[REVIEW] No CSP / deprecated `X-XSS-Protection`** — `app/Http/Middleware/SecurityHeaders.php:18-28`. No `Content-Security-Policy` (so XSS that slips through has no second line of defense); `X-XSS-Protection: 1; mode=block` is deprecated. **Fix:** add a baseline CSP (needs Vite/Inertia tuning — human-verified) and drop `X-XSS-Protection`.
- **[REVIEW] Plugin signature default off (posture)** — `app/Services/PluginService.php:233`. `plugins.signature.required=false` while the updater defaults on. Defensible (uploads gated off by default), but operators who enable uploads should be steered to require signatures. **Fix:** log a startup warning when `upload_enabled=true` and signatures are not required.

---

## Reliability / data-integrity findings

### High

- **[REVIEW] PO receiving has no transaction/lock** — `app/Http/Controllers/Purchasing/PurchaseOrderController.php:413-424`. `processReceiving` runs `$item->receive()` (increments product stock) in a bare loop with no `DB::transaction` and no row lock; a double-submitted "Receive" can over-receive / double-increment. The per-item cap is read outside any lock. **Fix:** wrap the loop in `DB::transaction`, `lockForUpdate()` the PO, and re-check `canReceiveItems()` inside.
- **[REVIEW] Web order cancel-restock race** — `app/Http/Controllers/Order/OrderController.php:251-385`. The cancel-restock branch (line 355) gates on a non-locked, pre-transaction model and never re-locks the order, so concurrent updates / a double-submit can both pass the guard and restock twice. It also lacks the SHIPPED/DELIVERED cancel guard the API has (`app/Http/Controllers/Api/OrderController.php:192`). **Fix:** `lockForUpdate()` the order at the top of the transaction, re-read status, and reject cancellation of shipped/delivered orders.
- **[REVIEW] WorkOrder complete/cancel race** — `app/Http/Controllers/Inventory/WorkOrderController.php:226-279,294-327`. `complete()`/`cancel()` re-check status only on the unlocked model; inside the transaction there is no lock/re-read, so a double-complete double-consumes component stock + double-produces assembly stock. **Fix:** lock the WorkOrder row and re-assert `status === 'in_progress'` before mutating stock.
- **[REVIEW] GraphQL cancel does not restock** — `app/GraphQL/Mutations/UpdateOrderMutation.php:81-90`. `updateOrder(status:"cancelled")` blindly `$order->update()` and never restocks inventory, unlike REST and web. Cancelling via GraphQL silently leaks decremented stock forever (count drift + over-triggered reorders). **Fix:** route all four surfaces (web/REST/GraphQL/MCP) through a shared `OrderService` cancel-restock method.

### Medium

- **[REVIEW] `OrderController::update` product lookup unscoped + unsafe** — `app/Http/Controllers/Order/OrderController.php:264`. `Product::where('id', $itemData['product_id'])->lockForUpdate()->first()` is not org-scoped and uses `first()`; a foreign/deleted id yields `null` (then a fatal on `$product->stock`) or operates cross-org. The store path validates org ownership; this edit path does not. **Fix:** `->forOrganization($order->organization_id)->firstOrFail()`.
- **[REVIEW] StockAudit complete race** — `app/Http/Controllers/Inventory/StockAuditController.php:354-403`. Unlocked status check; a concurrent double-complete re-applies every recount adjustment. **Fix:** lock the audit row + re-assert status inside the transaction.
- **[REVIEW] Notifications run inside the locked stock transaction** — `app/Observers/ProductObserver.php:35-51` + `app/Services/NotificationService.php:160-198`. The `updated` observer synchronously runs low/out-of-stock notification work (role query + per-user create + email) while the product row lock from `StockAdjustment::adjust` is held, lengthening lock hold under bulk order load. **Fix:** queue the notification work or fire it via `DB::afterCommit`.
- **[REVIEW] Webhook events advertised but never dispatched** — `app/Listeners/WebhookEventSubscriber.php:41-50`. Handlers are registered for `order_updated`, `order_status_changed`, `order_approved`, `order_rejected`, `stock_adjusted`, `low_stock_alert`, `out_of_stock_alert`, `purchase_order_created/received/cancelled`, but only `product_*` and `order_created` are ever fired via `do_action`. Every other advertised webhook silently never delivers. **Fix:** add the missing `do_action(...)` at the lifecycle points (or remove the dead subscriptions) + a test asserting each advertised event dispatches.
- **[REVIEW] StockTransfer.complete moves no stock** — `app/Http/Controllers/Inventory/StockTransferController.php:255-291`. Writes an `adjustment_quantity = 0` audit row and relocates no inventory (global stock model, no per-location columns); the insufficient-stock check guards a no-op. Documented in-code but misleading for any consumer that trusts location-level stock. **Fix:** implement per-location stock or drop the misleading check.

### Low

- **[REVIEW] ReturnOrder.receive double-restock** — `app/Http/Controllers/Order/ReturnOrderController.php:234-267`. Restock-on-receive checks `status === 'approved'` on the unlocked model; a double-submit can double-restock. (`store()` correctly locks the parent order; `receive()` does not lock the return.) **Fix:** lock the ReturnOrder row + re-assert status inside the transaction.
- **[REVIEW] `FileUpdateService::replaceFiles` non-atomic** — `app/Services/Update/FileUpdateService.php:218-260`. delete-then-copy per directory with no try/catch or rollback; a mid-loop failure (disk full, permissions) leaves the app half-replaced and unbootable. **Fix:** copy to a staging path and atomically swap, or restore-from-backup on failure.
- **[REVIEW] Import job `tries = 1`, deletes file in `finally`** — `app/Jobs/ProcessProductImportJob.php:33`. A transient failure permanently fails the import with no retry, and the `finally` deletes the uploaded file so the user cannot re-run. **Fix:** confirm imports are idempotent (upsert by SKU) and raise `tries`, or keep the file for a re-upload path.
- **[SAFE — recommended follow-up] PluginService list-path manifest guard** — `app/Services/PluginService.php:56`. The plugin-listing path decodes `plugin.json` without an `is_array` check (the boot loader was guarded in PR #136). A malformed manifest yields array-access-on-null warnings. **Fix:** add the same `is_array` guard.

---

## Verified safe (strong controls — no action needed)

- **Multi-tenancy (read + write):** every REST controller, GraphQL resolver, and MCP tool scopes by `organization_id` (404 on cross-tenant); `OrderService::create` batch-locks products `where('organization_id', $orgId)`.
- **Plugin uploader (the RCE surface):** Ed25519 detached-signature verification (fail-closed when required), `INVENTOROS_ALLOW_PLUGIN_UPLOADS` gate (off by default), zip-slip + zip-bomb defense, single-root enforcement, post-extract realpath containment.
- **In-app updater:** download-URL allowlist, `allow_redirects=false`, required Ed25519 verification of the archive before any file replacement, ZIP validation on extract and restore.
- **Webhooks:** HMAC-SHA256 over the exact transmitted bytes + `hash_equals`, create-time + delivery-time `PublicHostGuard` SSRF re-check (DNS-rebinding defense), redirects disabled, metadata-IP blocklist, bounded retries + backoff + `failed()` handler.
- **Auth &amp; Sanctum:** web login rate-limited (5/email+IP) + API `throttle:5,1`; token abilities allowlisted to the `Permission` enum (wildcard `*` admin-only); 2FA via Google2FA with `lockForUpdate` recovery-code consumption; no bearer bypass in the API/MCP middleware.
- **Mass assignment:** no `$guarded = []` anywhere; `User::booted()` blocks self role/org escalation; `organization_id` is always server-set.
- **Injection:** raw SQL uses static strings; the report builder maps user-chosen fields/operators through allowlists; GraphQL `query_max_depth=10`, `query_max_complexity=200`, introspection off in production.
- **Sales-order path (gold standard):** single `DB::transaction`, `lockForUpdate()` on all referenced products/variants, faithful before/after stock ledger, sequence-number retry; all four surfaces (web/REST/GraphQL/MCP) funnel through `OrderService`.
- **Manual stock adjustments:** re-fetch with `lockForUpdate()` inside the transaction before computing before/after.
- **Sensitive-data exposure:** `User::$hidden` hides password, remember_token, `two_factor_secret`, `two_factor_recovery_codes`; Inertia `auth.user` therefore leaks no secrets.

> **Test-coverage note:** concurrency tests exist for the order path (`StockAdjustmentRaceConditionTest`, `OrderServiceTest`, `OrderNumberRetryTest`) but **not** for PO receiving, work-order complete/cancel, stock-audit complete, or return receive — exactly the surfaces flagged above. Adding double-submit/concurrent tests there would lock in the High/Medium concurrency fixes.

---

## Appendix — Marketing site (`inventoros.com`) security (bonus)

Surfaced incidentally during this pass (separate repo: public marketing + plugin marketplace). The dangerous app surfaces (multi-tenancy, plugin upload, GraphQL, webhooks) **do not exist** there. Findings:

- **[REVIEW] High — Stored XSS** — `resources/js/Pages/Blog/Show.vue:49` renders admin-authored `content` via `v-html` with no sanitization; a malicious/compromised admin post executes JS for every (unauthenticated) visitor. **Fix:** sanitize on input (HTMLPurifier) or render through a Markdown pipeline that escapes raw HTML.
- **[REVIEW] High — `is_admin` in `User::$fillable`** — not exploitable today (registration passes only name/email/password) but a future `User::create($request->all())` would self-escalate to admin. **Fix:** remove from `$fillable`, set via `forceFill`/a gated action.
- **[SAFE — recommended] No auth rate limiting** — `routes/web.php` login/register have no `throttle` (credential stuffing + account enumeration). Add `throttle:6,1`.
- **[SAFE — recommended] Blog validation** — `excerpt`/`content` have no `max:`; `featured_image` is free-form `string`. Add `max:` + `nullable|url`.
- **[SAFE — recommended] Over-shared Inertia `auth.user`** — `HandleInertiaRequests` shares the whole user model. Share an explicit `['id','name','email','is_admin']`.
- **[SAFE — recommended] No security headers / reverse tabnabbing** — add a header middleware; add `rel="noopener noreferrer"` to `target="_blank"` links (`Marketplace/Show.vue:111,128,134`).
- **[REVIEW] No URL validation on plugin links** — `download_url`/`demo_url`/etc. are free-form strings rendered as `:href`; latent open-redirect / `javascript:` / SSRF if a plugin-admin create form is added without `nullable|url`.

---

## Recommended priority order

1. **Plugin slug path containment** (app, High security).
2. **GraphQL cross-tenant `exists` scoping** (app, tenancy IDOR) + **GraphQL cancel-restock parity**.
3. **Concurrency** — add transactions + `lockForUpdate` + re-check to PO receiving, work-order complete/cancel, web order cancel, stock-audit complete, return receive (and add double-submit tests).
4. **Marketing** — blog XSS sanitization + remove `is_admin` from `$fillable`.
5. **SAFE follow-ups** — GraphQL `max:` caps, marketing rate-limit/headers/`noopener`/validation, PluginService list-path guard.

---

## Resolution — v1.0.3 (2026-06-05)

Every actionable app finding above was fixed on branch `fix/audit-2026-06-03` and shipped in v1.0.3, each with regression tests:

- **Plugin slug path containment** — `PluginService` validates slugs against `^[A-Za-z0-9][A-Za-z0-9._-]*$` (rejecting `..`/separators) on activate/deactivate/delete, plus realpath containment before any delete; `loadPlugin` skips unsafe slugs without throwing.
- **GraphQL cross-tenant `exists` scoping** — `CreateProductMutation`/`UpdateProductMutation` resolvers reject `category_id`/`location_id` outside the caller's organization.
- **GraphQL cancel-restock parity** — a shared `OrderService::cancel()` (locked, idempotent, rejects shipped/delivered) now backs the web, REST, and GraphQL surfaces; GraphQL no longer leaks stock on cancel.
- **`UpdateController::check` is_admin guard** + **backup-file validation** (`^[A-Za-z0-9._-]+\.zip$` + membership in `listBackups()`).
- **GraphQL `max:` caps** on free-text args (description/notes/address/customer_address) to match REST.
- **SSRF guard fails closed** on empty DNS in production (`PublicHostGuard`).
- **Nonce-based CSP** added (Vite + Ziggy nonce, vue-i18n JIT), deprecated `X-XSS-Protection` dropped, `camera=(self)` for the scanner.
- **Concurrency** — `DB::transaction` + `lockForUpdate` + status re-check added to PO receiving, work-order complete/cancel, stock-audit complete, web order cancel, and return receive (each with a double-submit regression test).
- **Web order-update product lookup** is now org-scoped + `firstOrFail()`.
- **Stock notifications deferred** to `DB::afterCommit` (shorter lock hold).
- **All advertised webhook events now dispatch** — `stock_adjusted`, low/out-of-stock alerts, `order_updated`/`order_status_changed`/`order_approved`/`order_rejected`, and `purchase_order_created`/`received`/`cancelled` fire via model observers (after commit), each covered by a dispatch test.
- **Updater auto-restores** from backup when file replacement fails (and `replaceFiles` now throws on a failed copy).
- **Import job** retries (`tries=3` + backoff) with idempotent SKU upserts; the upload is kept until the final attempt.
- **Plugin manifest list guard** + a startup warning when uploads are enabled without signature verification.
- **Signed releases** — the cpanel-release workflow now produces a detached Ed25519 `.sig`; the public key ships in `.env.example`.

**Deferred (documented, not a regression):** per-location stock for `StockTransfer.complete` remains a feature-sized change; the existing no-op (with its in-code explanation) stands. The marketing-site (`inventoros.com`) appendix items are tracked and fixed in that repo separately.

Full suite green (1248+ tests) and the frontend build clean at release.
