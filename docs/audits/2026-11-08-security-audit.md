# Security audit — 2026-11-08

## Executive summary

The 58 open GitHub Dependabot alerts do not match the dependencies committed on
`main` at `6a0894534d006da5395ab60d69226f2880d07d9c`. Comparing every alert's
vulnerable version range against every matching lockfile entry found **zero
affected entries**: 43 alerts concern patched packages and 15 concern packages
removed from the dependency tree. Both `npm audit --package-lock-only` and
`composer audit --locked --abandoned=report` report no known vulnerabilities.
No dependency versions were changed for this audit. The
[per-alert reconciliation](2026-11-08-dependabot-reconciliation.json) records all
58 advisory ranges and the exact locked versions checked.

GitHub's dependency graph is disabled. Its snapshot API explicitly refused the
current inventory for that reason; the GraphQL API returned zero dependency
manifests. Existing alerts still reference old versions (for example,
`shell-quote` 1.8.3, while the lockfile contains 1.12.0). The owner needs to enable
**Dependency graph** in the repository's security settings before GitHub can
reconcile its inventory. No alerts were dismissed or marked as false positives.

The application review found separate authorization and resource-exhaustion
issues. The accompanying patches address these independently of the stale
dependency count. The highest priorities are installer state verification,
token-bearing email origins, and warehouse authorization during sales stock
movements. Security maintenance debt is moderate: automated all-severity
dependency audits already run on changes and weekly, but GitHub inventory
scanning was disabled and some shared service boundaries lacked enforcement.

The role-management review additionally confirmed privilege escalation and
cross-organization exposure through shared role definitions. Those boundaries
are patched alongside warehouse authorization.

## Dependency inventory

GitHub reported 1 critical, 26 high, 27 moderate and 4 low alerts, all associated
with `package-lock.json`. These are advisory counts, not counts of independent
application vulnerabilities. GitHub labels the old entries as development
dependencies; Axios is used by the browser application, so that label alone
would not establish that an affected version is harmless.

| Package | Alerts | Version(s) committed on main | Assessment |
| --- | ---: | --- | --- |
| axios | 21 | 1.20.0 | Outside all reported vulnerable ranges |
| vite | 10 | 7.3.7 | Outside all reported vulnerable ranges |
| tar | 6 | Absent | Removed |
| postcss | 3 | 8.5.31 | Outside all reported vulnerable ranges |
| brace-expansion | 2 | Absent | Removed |
| picomatch | 2 | 4.0.7; nested 2.3.2 | Both outside their reported vulnerable ranges |
| qs | 2 | Absent | Removed |
| shell-quote | 2 | 1.12.0 | Outside both reported vulnerable ranges |
| baseline-browser-mapping | 1 | Absent | Removed |
| browserslist | 1 | Absent | Removed |
| follow-redirects | 1 | 1.16.2 | Outside reported vulnerable range |
| form-data | 1 | 4.0.6 | Outside reported vulnerable range |
| lodash-es | 1 | Absent | Removed |
| minimatch | 1 | Absent | Removed |
| nanoid | 1 | 3.3.20 | Outside reported vulnerable range |
| postcss-selector-parser | 1 | Absent | Removed |
| rollup | 1 | 4.65.0 | Outside reported vulnerable range |
| source-map-js | 1 | 1.2.2 | Outside reported vulnerable range |

Examples of upstream patch boundaries are the
[shell-quote advisory](https://github.com/advisories/GHSA-w7jw-789q-3m8p),
[Vite/launch-editor advisory](https://github.com/advisories/GHSA-587h-7wmj-h5pm),
and [Axios advisory](https://github.com/advisories/GHSA-j8rh-479h-cp32).
GitHub documents how its
[dependency graph](https://docs.github.com/en/code-security/concepts/supply-chain-security/dependency-graph)
derives this inventory from lockfiles and dependency submissions.

## P0 — immediate security fixes

### High: role management permits privilege escalation and cross-tenant changes

**Where:** `app/Http/Controllers/Admin/RoleController.php` and
`app/Http/Requests/Admin/Role/StoreRoleRequest.php` / `UpdateRoleRequest.php`.

Delegated role managers could grant permissions beyond their own or attach
permission sets from another organization. Tenant administrators could also
modify shared system roles used by other organizations. Shared role member
lists serialized users outside the active organization.

Shared/system role definitions are now read-only. Organization-owned roles and
permission sets must remain inside the actor's permission boundary, and requested
permission names must be recognized core or active-plugin permissions. Member
lists are scoped to the active organization. Regressions cover escalation,
shared-role writes, foreign permission sets, member isolation and permitted
delegation. **Effort:** medium.

Existing deployments should review their saved shared-role definitions and
permission-set attachments for earlier unauthorized changes. These fixes enforce
future requests; they do not rewrite existing grants.

### High: installer state checks fail open on database errors

**Where:** `app/Http/Controllers/Install/InstallerController.php`,
`app/Http/Middleware/UseFileDriversUntilInstalled.php`, and
`app/Http/Middleware/CheckInstallation.php`.

An exception while reading the installed flag previously meant “not installed.”
During an existing installation's database outage, public setup endpoints could
therefore accept new database configuration and account provisioning. The shared
`app/Support/InstallationState.php` now returns HTTP 503 when state cannot be
verified, recognizes genuinely fresh databases, and checks legacy accounts as a
second indication that setup is closed. Successful new installations record an
`INVENTOROS_INSTALLED` environment marker outside the database. The installer
completion and fresh-install paths have regression coverage. **Effort:** medium.

### High, deployment-dependent: token-bearing email URLs trust the request host

**Where:** `app/Providers/AppServiceProvider.php`,
`app/Mail/PortalPasswordResetEmail.php`, `app/Notifications/AccountInvitation.php`,
and `app/Services/Portal/PortalInvitationService.php`.

On a deployment accepting arbitrary Host headers, password reset and invitation
links could inherit an attacker-controlled origin. `app/Support/ApplicationUrl.php`
now builds those URLs from the configured `APP_URL`, including its scheme and
subdirectory, without changing ordinary navigation. Portal invitation signatures
are calculated over that configured origin. Regressions cover staff resets,
portal resets, account invitations, and signed portal invitations. Operators must
set `APP_URL` to the public application URL. **Effort:** small.

### High: sales stock movements bypass warehouse assignments

**Where:** `app/Services/OrderService.php` and
`app/Services/ProductLocationStockService.php`.

Sales creation used organization-wide stock availability and consumed bins across
all warehouses, even for users restricted to particular warehouses. The same
shared services serve browser, REST, GraphQL and MCP entry points. Consumption now
receives the actor, limits eligible bins to their warehouse assignments, and
refuses an inaccessible-stock shortfall before modifying bins. Sales edits and
restocking enforce the same boundaries. Reserved locations and explicit fulfilling
warehouses are checked as well. **Effort:** medium.

The current data model does not attach batch/serial records to warehouses or
preserve original order source bins. Restricted operations are therefore refused
when stock ownership cannot be established safely; unrestricted staff can perform
them. Recording source bins and per-location tracked stock is a strategic follow-up.

## P1 — resource-exhaustion fixes

| Finding | Where | Impact and remediation | Effort |
| --- | --- | --- | --- |
| Negative GraphQL limits remove the SQL limit | Seven legacy list resolvers in `app/GraphQL/Queries` | Clamp the lower bound while preserving `limit: 0`; negative values cannot load an entire table. | Small |
| GraphQL batching charges many operations to one rate-limit hit | `config/graphql.php` | Disable batching so a single HTTP request cannot execute an arbitrary array of operations. Ordinary requests remain supported. | Small |
| Nested GraphQL relationships amplify response size | `app/GraphQL/Types` | Bound collection resolvers and account for collection cardinality in query complexity before execution. | Medium |
| Webhook receivers control unbounded response size | `app/Jobs/WebhookDeliveryJob.php` | Cap decoded HTTP response bytes during transport and read only the diagnostic prefix, including chunked and compressed responses. Failed oversized transfers retain retry behavior. | Small |

Nested object collections now return at most 100 records. A separate 50,000-node
estimated response budget preserves the existing 200-field complexity and
10-level depth budgets. GraphQL approval candidates are bounded at the database
before authorization checks, while existing browser/count callers retain their
full results. Webhooks stop at 1 MiB of decoded response data and retain at most
5,000 bytes for diagnostics.

### Patched source references

| Boundary | Primary source reference |
| --- | --- |
| Role delegation | [`RoleController.php:320`](../../app/Http/Controllers/Admin/RoleController.php#L320) |
| Installer state | [`InstallationState.php:14`](../../app/Support/InstallationState.php#L14) |
| Email origins | [`ApplicationUrl.php:23`](../../app/Support/ApplicationUrl.php#L23) |
| Order fulfillment | [`OrderService.php:113`](../../app/Services/OrderService.php#L113) |
| Bin consumption | [`ProductLocationStockService.php:179`](../../app/Services/ProductLocationStockService.php#L179) |
| Negative limits | [`ProductsQuery.php:122`](../../app/GraphQL/Queries/ProductsQuery.php#L122) and the six equivalent legacy list queries |
| GraphQL batching | [`graphql.php:136`](../../config/graphql.php#L136) |
| Nested list budget | [`ListResourceLimits.php:14`](../../app/GraphQL/Support/ListResourceLimits.php#L14) |
| Webhook transfer | [`WebhookDeliveryJob.php:347`](../../app/Jobs/WebhookDeliveryJob.php#L347) |

## P2 — planned security maintenance

- Restore GitHub dependency graph scanning, then verify the alert count is
  reconciled automatically. Dependabot security updates and secret scanning were
  also disabled when inspected; enabling these is an owner-level follow-up.
- Pin third-party GitHub Actions to reviewed commit SHAs and declare explicit
  least-privilege workflow permissions. No `pull_request_target` execution path
  was found in the reviewed workflows.
- Add original-bin provenance and warehouse ownership for serials/batches so
  restricted users can safely reverse more stock operations.

## P3 — documentation

`SECURITY.md` previously described an older Tailwind 3/braces dependency exception
that no longer matched the committed Tailwind 4 lockfile. It now links to this
audit and the current all-dependency audit commands.

## Scope, evidence and limits

The 2.4.0 release artifact review additionally found development demo seeders
included in production packages. Running them manually could provision their
known demo accounts. `ReadmeDemoSeeder` and its `DemoDataSeeder` companion now
refuse production execution, including `--force`, before changing any records.
Both are excluded from Docker and cPanel artifacts, and package verification
rejects them. The follow-up package, updater, installer and seeder checks passed
76 tests with 265 assertions; release-candidate validation is recorded in
[pull request #337](https://github.com/Inventoros/Inventoros/pull/337).

The review covered dependency alerts and lockfiles, authentication and sessions,
organization and warehouse boundaries, REST/GraphQL/MCP entry points, installer
state, plugins and release updates, uploads/imports, outbound webhooks, and CI
security. Review findings were cross-checked across architecture, security,
performance, quality, reliability and failure-path passes.

Existing protections reviewed include tenant scoping, public-host validation with
DNS pinning and redirects disabled for outbound webhooks, upload size limits,
signed update archives, session revocation, and all-severity dependency audit CI.
These checks are evidence of specific protections, not a claim that the entire
application is vulnerability-free.

The audit used isolated local/in-memory test databases and did not inspect or
change a production deployment. GitHub code scanning returned “no analysis found”;
secret scanning is disabled, so neither is counted as a clean scanner result.
No full repository-history secret scan or external penetration test was performed.

## Validation

| Check | Result |
| --- | --- |
| Complete audit-change PHPUnit suite, PHP 8.4.19 / SQLite | 4,036 tests; 28,956 assertions; zero failures; 7 skips and 12 existing PHPUnit metadata deprecations |
| JavaScript unit tests | 226 passed |
| Production frontend build | Passed |
| Both bundled plugin rebuilds | Passed; committed bundles reproduced without changes |
| Changed/new PHP files | PHP 8.4 syntax checks passed |
| npm and Composer audits, including development dependencies | Zero reported advisories |
| All 58 GitHub advisory ranges | Valid semver ranges; zero affected locked entries |

New regressions cover installer outages and completion, poisoned request origins,
warehouse mutations across browser/REST/GraphQL/MCP, role boundaries and legitimate
permission-set delegation, GraphQL limits and response budgets, and bounded webhook
responses. A real cURL transfer of a compressed 2 MiB response was stopped at the
1 MiB decoded-data cap.

GitHub also runs the suite against MySQL and PostgreSQL, plus production-only
installation, Docker, JavaScript, dependency audits, demo generation and end-to-end
checks. The live results and final review state are recorded in
[pull request #336](https://github.com/Inventoros/Inventoros/pull/336).
