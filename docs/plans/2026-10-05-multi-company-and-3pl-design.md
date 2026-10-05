# Multi-company and 3PL: core design

Status: memberships and switching implemented (Inventoros#257); inter-company transfers implemented in the change stacked on it; 3PL needs no further core primitive (decision below). Plugin SDK gap G11, and the core capability the Multi-Company (`multi-company`) and 3PL Management (`3pl`) plugins depend on.

## Why

The organization is Inventoros's tenant boundary. Every tenant row carries `organization_id`, `OrganizationScope` constrains every query on a tenant model to the signed-in user's organization, and hundreds of controllers, services, policies, GraphQL resolvers and MCP tools compare a record's `organization_id` with `$user->organization_id`. A user belonged to exactly one organization (`users.organization_id`).

Two paid plugins need more:

- **Multi-Company** groups organizations that one business runs (a holding company and its subsidiaries). The same people work in several of them, and stock moves between them as a controlled, audited operation.
- **3PL Management** stores and ships inventory that belongs to its clients. Each client's stock, orders and bills must be isolated from every other client's, and the 3PL's staff work across all of them.

Neither can be built safely in a plugin alone: who the user is "working as" is decided by core on every request, on every surface, before any plugin code runs.

## Constraints

1. Single-organization users and installs behave exactly as before: same data, same permissions, no new UI, no new failure modes.
2. The organization stays the isolation boundary. No change may let a query, a write, a token, a job or a cached value reach an organization the user is not a member of.
3. Minimal and generic: core gains "a user can belong to several organizations and works in one at a time", plus a way for core services to work inside an organization the actor has been authorized for. Grouping, consolidated reporting, inter-company pricing, client billing and the like stay in the plugins.

## Decision 1: the active organization is `$user->organization_id`, resolved per request

### Options considered

| Option | Verdict |
|--------|---------|
| A. Replace every `$user->organization_id` read with a tenancy service call | Rejected. 275 files read it; a missed one is a cross-tenant hole that no test would name. |
| B. Persist the chosen organization on the user row when switching | Rejected. The row is shared by every browser session and every API token of the user: switching in one tab would move the user's tokens and other sessions into another organization. |
| C. One user account per organization | Rejected. Duplicate credentials, two-factor secrets and audit identities; no switching. |
| **D. Keep `users.organization_id` as the home organization, and make the attribute mean "the active organization" on the authenticated instance only** | **Chosen.** Every existing check applies unchanged to whichever organization is active; the choice of organization is made once, in one place, per request. |

### How the active organization is resolved

`App\Services\Organizations\ActiveOrganization` sets it in memory, never on the row (`User::useOrganization()`, which syncs the attribute's original value so a later `save()` of other fields never writes it, and refuses to save a changed `organization_id`/`role` on a switched instance).

| Context | Active organization |
|---------|--------------------|
| Browser session | Home organization, until the user switches. The choice is stored in the session as `{user_id, organization_id}`, re-checked against the user's memberships every time the user is resolved (`Authenticated` event), dropped when it no longer matches (membership withdrawn, organization disabled or deleted, a different user) and cleared at every staff sign-in (`Login` event). Customer portal sign-ins (the `customer` guard) are ignored. |
| API token (REST, GraphQL, MCP) | The organization the token was created in (`personal_access_tokens.organization_id`, stamped by `User::createToken()`). `User::withAccessToken()` applies it; `Sanctum::authenticateAccessTokensUsing()` refuses a token whose membership is gone. The browser's choice never reaches a token request. Legacy tokens without an organization work in the home organization. |
| Queued job, command, scheduler | No signed-in user, so no scope (unchanged). A job that acts for a user records the organization at dispatch and loads the user into it with `ActiveOrganization::userIn($userId, $organizationId)`, which returns null when they are no longer a member (`ProcessOrderImportJob`, `ProcessProductImportJob`, `ScheduledReportRunner` do this now). |
| Core service working in a second organization | `ActiveOrganization::runAs($user, $organizationId, $permissions, $callback)`: checks the membership and the permissions there, then runs the callback inside `OrganizationContext::run()`, which narrows `OrganizationScope` and the `organization_id` stamped on new tenant rows to that organization for the callback only. `OrganizationContext` is a scoped binding, so an override never outlives its request or job. |

### Data model

| Table | Change |
|-------|--------|
| `organization_user` (new) | `organization_id`, `user_id`, `role` (admin, manager, member: the base role in that organization), unique per pair. Every existing user gets a row for their home organization; `User` keeps that row in step with `users.organization_id` / `users.role`. Infrastructure like `users` and `roles`: no `OrganizationScope`. |
| `role_user.organization_id` (new) | Custom and system role assignments belong to one organization. Backfilled with the user's home organization. `User::roles()` reads, attaches, syncs and detaches within the active organization, so the Administrator system role held in one organization grants nothing in another. The unique index becomes `(role_id, user_id, organization_id)`. Eager loading (`User::with('roles')`, used by user lists) matches each user's home organization. |
| `personal_access_tokens.organization_id` (new) | The organization the token works in. Backfilled with the token owner's home organization. |

### Permissions

All checks read the active organization: the base role is the membership's role, custom roles are those assigned in that organization, warehouse assignments are filtered by the warehouses' organization (as they already were), and `auth.permissions`, `api.permission`, the web `permission` middleware, GraphQL and MCP gates follow. A user who is an administrator at home and a plain member elsewhere is a plain member there.

The home organization owns the account. Its administrators edit, reset and delete the user on the existing Users screens, which keep listing home users only, with two limits once the account also works elsewhere (`RoleAssignmentGuard`):

- A non-admin (a delegated user manager) may manage a user only if that user holds no admin or manager role, and no permission the manager lacks, in ANY organization they belong to. Without this, a user manager could reset the password of a home user who administers another organization and sign in there.
- Changing the email or password of, or deleting, an account that belongs to other organizations needs an administrator of each of them (an organization's last administrator is never deleted). The name and the home base role stay editable by home administrators. The user changes their own email and password from their profile as before.

Organizations themselves can be disabled (`is_active`): nobody works in a disabled or deleted organization, home included. A session lands in another organization the user belongs to (or in none, with no tenant data and no role), and tokens bound to it, and legacy tokens of its users, stop authenticating. Memberships of other organizations are granted and withdrawn through `OrganizationMembershipService` (the multi-company plugin provides the screens), which refuses the home organization and the last administrator, removes the user's roles, warehouse assignments and API tokens in that organization with the membership, writes the security log in that organization and fires `organization_member_added` / `organization_member_removed`.

### Sessions and the browser

- `POST /organizations/switch` checks the membership, stores the choice, clears `active_warehouse_id` (it belongs to the previous organization), regenerates the session id and the CSRF token and destroys the old session (a session id or form captured before the switch is useless after it), logs `organization.switched` in both organizations, fires `organization_switched` and lands on the dashboard (the page the user was on belongs to the previous organization).
- The top bar shows the organization switcher only to members of two or more organizations.
- A switch applies to the whole session, so a second tab opened earlier still shows the previous organization's forms. Every Inertia visit and axios request carries the organization its page was rendered for (`X-Inventoros-Organization`); `EnsureActiveOrganizationMatches` refuses a write whose header names another organization (409 for JSON, otherwise back to the dashboard with a warning) instead of letting it land in the active one. Switching and signing out work from any tab.

### Known limits (deliberate, for the plugins to extend)

- Organization-wide notifications (low stock, new orders, returns), scheduled report recipients and assignee pickers address home users. Members added from other organizations are not recipients in this version; the Multi-Company plugin can add them through the hooks.
- After-commit hooks fired by a core service called inside `runAs()` (`stock_changed`, `stock_adjusted`, ...) run in the request's active organization. Listeners must use the record's own `organization_id`, which they already must in queued jobs.
- Core has no screens to add members or create organizations; that is the Multi-Company plugin's job (`OrganizationMembershipService::add()`, `createOrganization()`).
- Activity log entries belong to the organization of their subject (a user account's entries to its home organization; work done inside `runAs()` to that organization), not to the organization the actor happened to have active.
- A request already in flight when the user switches in another tab finishes in the organization it started in, and with the array, file or database session drivers it may write the old session data back after the switch (Laravel saves the whole session at the end of each request). The stored choice is re-checked against the memberships on the next request, so this can never reach an organization the user does not belong to; at worst the next page shows the previous organization again.
- The stale-tab guard compares the header with the active organization. After an Inertia reload the tab adopts the new organization and its header with it, so a form re-submitted from that page is sent to the new organization; its records then answer 404 there (they belong to the previous one) rather than being changed. The guard is a safety net for writes, not an isolation boundary: the boundary is the scope and the membership checks.

### Isolation test inventory

`tests/Feature/Organizations/` (every test signs in once and resolves the user from the session again on each request, as a browser does):

| Surface | Test |
|---------|------|
| Web pages, reads | `OrganizationSwitchingTest::test_switching_confines_every_page_to_the_chosen_organization` (index, show of the other organization's record: 404) |
| Web writes | same test: update and delete of the other organization's record 404 and change nothing; a new record lands in the active organization |
| Shared props, regional settings | same test (`auth.organization`, `auth.user.organization_id`, `regional.currency`) |
| Session fixation and renewal | `test_switching_renews_the_session_and_csrf_token_and_forgets_the_warehouse`, `test_the_session_from_before_the_switch_no_longer_signs_anyone_in`, `test_signing_in_always_starts_in_the_home_organization`, `test_an_active_organization_stored_for_another_user_is_ignored`, `test_a_user_cannot_switch_into_an_organization_they_do_not_belong_to` |
| Membership changes mid-session | `test_a_withdrawn_membership_ends_on_the_next_request`, `test_a_disabled_organization_ends_the_session_there_on_the_next_request`, `test_disabled_and_deleted_organizations_cannot_be_switched_to` |
| Permissions per organization | `test_permissions_are_those_held_in_the_active_organization`; `OrganizationMembershipTest::test_roles_are_held_per_organization` (custom role, Administrator system role, a relation eager-loaded for home) |
| Stale browser tab | `test_a_write_from_a_tab_still_showing_the_previous_organization_is_refused` |
| Notifications | `test_notifications_of_another_organization_stay_in_it` |
| Exports | `test_an_export_made_in_another_organization_cannot_be_downloaded` |
| Warehouses | `test_the_active_warehouse_must_belong_to_the_active_organization` |
| Cache keys | `test_cached_settings_follow_the_active_organization` (settings are cached per organization id) |
| REST | `TokenOrganizationBindingTest::test_rest_requests_work_in_the_token_organization_only`, `test_the_browser_session_organization_does_not_reach_token_requests`, `test_permissions_are_those_held_in_the_token_organization` |
| Token binding | `test_a_token_is_bound_to_the_organization_it_was_created_in`, `test_a_token_of_a_withdrawn_membership_stops_authenticating`, `test_a_token_of_a_disabled_organization_stops_authenticating`, `test_a_legacy_token_without_an_organization_works_in_the_home_organization`, `test_signing_in_over_the_api_*`, `test_tokens_are_listed_and_revoked_per_organization`, `test_tokens_minted_over_the_api_stay_in_the_calling_organization` |
| GraphQL | `test_graphql_works_in_the_token_organization_only` (list, single record, mutation) |
| MCP | `test_mcp_works_in_the_token_organization_only` (`who_am_i`, `list_products`, `get_product`, `adjust_stock`) |
| Customer portal | `PortalOrganizationIsolationTest` (a staff switch and a portal sign-in in the same browser do not affect each other) |
| Queued jobs | `BackgroundOrganizationContextTest::test_a_queued_order_import_runs_in_the_organization_it_was_started_in`, `..._fails_once_the_membership_is_withdrawn` |
| Scheduled reports | `test_a_scheduled_report_runs_as_its_owner_in_the_schedule_organization`, `..._is_skipped_once_its_owner_left_the_organization` |
| Service context | `test_run_as_confines_scoped_queries_and_new_rows_to_the_organization`, `..._restores_the_scope_when_the_callback_throws`, `..._scopes_queued_work_that_has_no_signed_in_user`, `..._refuses_non_members_and_missing_permissions`, `test_the_context_override_is_per_request_and_job` |
| Account integrity | `OrganizationMembershipTest` (home membership sync, switched instance never writes the home row, one assignment per role, user and organization, backfill) |
| Account takeover across organizations | `CrossOrganizationAccountTest` (user manager vs an admin elsewhere, on web and REST; permissions held elsewhere; home administrator vs credentials, email and deletion; administrator of every organization may; another organization's last administrator) |
| Disabled organizations | `OrganizationSwitchingTest::test_a_disabled_home_organization_holds_no_data_for_its_users`, `TokenOrganizationBindingTest::test_tokens_of_a_disabled_home_organization_stop_authenticating` |
| Logs, settings, licences, approvals | `BackgroundOrganizationContextTest::test_work_inside_run_as_is_logged_and_configured_in_that_organization`, `test_changes_to_a_user_account_are_logged_in_its_home_organization`, `test_an_approval_decision_reaches_the_requester_in_the_request_organization`, `test_a_queued_product_import_fails_once_the_membership_is_withdrawn` |
| Broadcasts | Not applicable: core registers no broadcast channels. Notifications are database rows, covered above. |

## Decision 2: inter-company transfers are a paired, audited core operation

Moving stock between two organizations must be atomic (both sides or neither), checked in both organizations, and visible in both ledgers. A plugin cannot do that safely from outside: it would have to reach into the second organization's rows past `OrganizationScope`.

`App\Services\Organizations\InterCompanyTransferService::transfer($actor, $from, $to, $lines, $notes, $idempotencyKey)`, tested in `tests/Feature/Organizations/InterCompanyTransferServiceTest.php` (both ledgers, bins, variants, all-or-nothing, membership and `transfer_stock` on each side, wrong-organization records, warehouse access, idempotency, a request in either organization, webhooks of both organizations):

- Authorizes the actor in BOTH organizations (membership plus `transfer_stock` in each, and warehouse access to the named locations in each; a user restricted to some warehouses must name accessible locations on both sides, so variant stock, which has no locations, is out of reach for them), checks every product, variant and location belongs to the organization named for it, and refuses serial- or batch-tracked products (their units would need their own transfer).
- Locks every product and variant involved in ascending id order, then, in one transaction, books an `inter_company_out` adjustment in the source organization (inside `runAs($from)`, never below zero) and an `inter_company_in` adjustment in the destination (inside `runAs($to)`). Both adjustments reference the same `inter_company_transfers` row, which records both organizations, the actor, the lines and both adjustment ids. Bins move with the totals, as for any adjustment.
- An optional idempotency key makes a retried call return the first transfer instead of moving stock twice.
- Fires `inter_company_transfer_completed` after commit.

The Multi-Company plugin builds its documents (inter-company sale, purchase, in transit, pricing) on top and calls the service for the stock movement.

## Decision 3: 3PL clients are organizations

### The two models

**A. Ownership dimension inside one organization.** The 3PL is one organization; each client is a "party", and an `owner_party_id` is added to stock (on `product_location_stocks`, or a separate ownership ledger like Consignment's `plg_consignment_ownership_ledger`).

**B. One organization per client.** Each client is an organization holding the client's products, stock, orders, customers and documents. The 3PL's staff are members of every client organization (Decision 1); the 3PL operator's own organization holds the plugin's configuration, rate cards and billing.

### Evaluation

| Concern | A. Owner dimension | B. Organization per client |
|---------|--------------------|----------------------------|
| Isolation between clients | A new, second scope that every core read, write, report, export, search, webhook, REST, GraphQL and MCP path would have to honour. Each path missed is a leak between clients, and there are hundreds. | The existing organization boundary, the most tested part of core, plus this design's membership checks. Nothing new to miss. |
| Core risk | High: changes the stock model every module reads. | None beyond Decision 1. |
| SKUs | Two clients using the same SKU collide (SKUs are unique per organization). | Each client keeps its own SKUs. |
| Valuation, reports | Core reports would value client-owned stock as the 3PL's own; every report needs an owner filter. | Correct by construction: each client's reports cover its own stock. |
| Client-facing access | Needs per-party filtering of every screen. | A client's own users are home users of their organization with whatever role the 3PL gives them, so the whole application works for them already, isolated. The customer portal (G16) remains available per organization. |
| Billing per client | Aggregate per party across shared tables. | Natural: per organization. |
| Shared physical warehouse | Natural: one set of bins. | Each client organization has its own warehouse and location rows describing the same building and bins. Physical capacity and occupancy across clients is the plugin's map (below). |
| Operator views across clients | Natural. | The plugin aggregates per client organization through `runAs()`, which checks the operator's membership and permissions in each. |

### Decision

**B.** It has the strongest isolation (the boundary core already enforces everywhere) and adds no core risk beyond Decision 1. The costs (per-client warehouse and bin rows, aggregation across organizations) are plugin work, contained in plugin tables, and every cross-client read goes through a membership check.

Consequently there is **no core ownership primitive** (no `owner_party` column, no core ownership ledger). Consignment keeps its own ownership ledger: consignment is ownership inside one business, which does not need a tenant boundary, while 3PL clients do.

### What the 3PL plugin must do, in its own tables

- `plg_3pl_clients`: client account, its `organization_id` (created with `OrganizationMembershipService::createOrganization()`), status, contract, SLA settings. The operator's staff are added to each client organization with `add()`; client users are created as home users of the client organization by its administrators (the 3PL operator or the client).
- `plg_3pl_sites` and `plg_3pl_bins`: the physical building and bins, owned by the operator organization, with `plg_3pl_bin_locations` mapping each physical bin to the per-client `product_locations.id` that represents it in each client organization. Capacity, occupancy and putaway across clients are computed here, by reading each client organization through `runAs()`.
- Receiving (ASNs), pick, pack, ship and returns run as core documents in the client's organization (`runAs($operator, $clientOrg, ...)` calling the core services with the member the callback receives), so client stock, orders and shipments stay in the client's ledgers.
- `plg_3pl_rate_cards` (versioned), `plg_3pl_billing_events` (immutable, each referencing its source record, client organization and rate card version, reproducible by re-running), `plg_3pl_storage_snapshots` (daily, per client organization and physical bin), `plg_3pl_invoices`, `plg_3pl_sla_events`: all keyed by client `organization_id`, written by the operator organization's jobs, which record the organization they act in.
- Holds on client stock for outbound orders wait for the reservations design (G4, `2026-10-05-stock-reservations-design.md`).
- Tests: a client user and an operator user, across REST, GraphQL, MCP, web, portal and jobs, never reaching another client's organization.

### What the Multi-Company plugin must do

- `plg_multi_company_groups` and group membership of organizations; screens to create companies (`createOrganization()`) and manage members (`add()`, `changeRole()`, `remove()`), checking `manage_organization` in the target organization first (`ActiveOrganization::authorize()`).
- Consolidated, read-only reporting that reads each company through `runAs()` with the viewer's permissions there, never with `withoutGlobalScope()`.
- Inter-company documents and pricing on top of `InterCompanyTransferService`.
