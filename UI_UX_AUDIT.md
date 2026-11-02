# Inventoros UI/UX, design and localization audit

Audit date: 2 November 2026. **Final rendered verification is running.** Counts below use root causes, not affected DOM nodes or translated strings.

## Executive summary

The approved identity is retained: restrained blue actions, semantic light/dark surfaces, compact navigation and operational tables filling the workspace. The main defects were dialog focus, unnamed controls, mixed-language feedback, incomplete Arabic direction, stale translated labels, narrow-screen overflow and table alignment. The current register contains **31 findings: 29 implemented fixes and two remaining P2 items**. No P0 defect was found. Final P1 closure awaits the strict rendered results.

## Application discovery and audit checklist

Laravel 13, Inertia 3, Vue 3, Tailwind 4, Lucide, vue-i18n 11 and Vite form the stack. AppLayout, GuestLayout and PortalLayout share semantic tokens in `resources/css/design-tokens.css`, `dashboard.css` and `tables.css`.

The [application map](docs/audits/ui-ux-application-map.json) inventories 123 page Vue files: 118 top-level templates and five embedded partials. Initial discovery counted 64 reusable Vue components, 83 form sources, 24 dialog/teleport sources and 71 table templates in 61 files. There are now 66 components after adding DialogSurface and Pagination. The normalized map has 119 route candidates, including one API route sharing a controller. An inventoried template is not automatically a visually verified screen.

- [x] Map routes, templates, roles, shared components, conventions and localization files.
- [x] Review spacing, typography, colors, states and table architecture in source and screenshots.
- [x] Run baseline and corrected browser sweeps across all seven requested widths.
- [x] Implement shared dialog, keyboard, form, translation, responsive and contrast fixes.
- [x] Exercise full-table view, filters, pagination, editable cells and focus through regression tests.
- [x] Check every supported frontend locale for keys, placeholders, plurals and message compilation.
- [ ] Review final strict sweep, conditional states and optional plugin evidence.
- [ ] Record final CI results and refresh screenshots after the last fixture change.

## Audit coverage

### Routes and states

Staff coverage includes dashboard; product index/create/edit/detail/reset preview/reset status; variant replenishment; categories/locations; warehouses/balances; stock adjustments, transfers, reservations, audits/count history/cycle schedules; work orders; customers; orders/payments; returns/order selection; purchase orders/receiving; approvals; import/export; every report/report builder; users, roles, activity, plugins, updates; profile and all settings sections. Embedded forms and overlays were inspected in source; route navigation alone is not claimed as interaction coverage.

The browser harness seeds 98 staff URL states. Guest checks cover login, enabled registration, password request/reset, portal login/request/reset and signed invitation. Portal checks cover dashboard, order index/detail/return form and return index/detail. Separate contexts render email verification, two-factor challenge and restricted-member 403. Installer welcome, requirements, database, administrator and completion pages are rendered by toggling only the disposable database's installation flag. No real installation or administrator-account submission occurs.

The optional plugin sweep activates Hello World and Tax Billing only in its disposable database, checks their pages and representative embedded components, then deactivates them. Issuing invoices, sending messages, buying labels, installing updates and destructive production actions are excluded.

### Viewports, themes and languages

Widths: **320, 375, 430, 768, 1024, 1440, 1920**. Height is 812 below tablet and 1080 otherwise; tall-dialog regressions also use 320x568. Audit screenshots are captured at 320/1440; the public gallery is 1920x1080. Dark-mode automation covers staff routes at 1440 and six layout families at 320. This is not every combination of route, viewport, theme and language.

All **14 languages** are covered by source/catalog checks: ar, de, en, es, fr, it, ja, ko, nl, pl, pt-BR, ru, tr, zh-CN. Each frontend catalog has **3,324 leaf keys**. Key/interpolation/plural parity and message compilation pass across all 14. The language browser sweep visits dashboard, product index/create, orders, account settings and reports at 320/1440. Arabic has an additional real browser assertion for RTL persistence and mirrored sidebar placement. French headings were manually verified to update and return to English without navigation.

Backend JSON catalogs contain **148 shared feedback/validation entries per locale**, with placeholder and encoding checks. Standard Laravel auth/password/pagination/validation catalogs remain in use. Internal protocol values, CSV/API identifiers and user data are preserved. Optional plugin-owned copy and exception/provider diagnostics outside these catalogs remain a translation gap; this is not a claim that every possible backend exception has been translated.

## Issues discovered and fixes implemented

Implemented rows require final regression sign-off. References name the main shared fix, not every affected page.

| ID | Severity | Finding and impact | Fix / main files |
| --- | --- | --- | --- |
| 01 | P1 | Dialogs lost/leaked focus, especially reverse Tab and mobile Actions openers. | Native DialogSurface, initial focus, containment, Escape and restoration after close; Modal and 25 manual overlays use it. |
| 02 | P1 | Nested overlays could release another scroll lock or collapse an expanded table. | Reference-counted `lib/scrollLock.js`; shared use in shell, dialogs and TableViewport. |
| 03 | P1 | Duplicate Ctrl/Cmd-K handlers could toggle search twice. | GlobalSearch owns the shortcut; shell handles drawer Escape. |
| 04 | P2 | Gallery keyboard listeners survived unmount. | ImageGallery removes its window listener on unmount. |
| 05 | P2 | Image zoom relied on pointer interaction and unnamed controls. | Native image button plus named close/previous/next controls. |
| 06 | P1 | Form fields, filters and repeated rows lacked associated labels. | Unique field/label IDs and names, including report fields and shipping URL. |
| 07 | P1 | Bulk checkboxes and icon-only actions lacked accessible names. | Named select-all/row checkboxes, reorder/delete buttons and return selections. |
| 08 | P1 | Arabic translation did not consistently change direction or geometry. | HTML lang/dir at render and switch; logical spacing/alignment in 108 Vue sources and CSS. |
| 09 | P1 | Missing/nested keys exposed raw translation paths. | Fix transfer/status/SKU references in all 14 catalogs; source-use regression guard. |
| 10 | P1 | Server feedback and custom validation stayed English. | Localized controller messages, catalogs and verification status; ServerFlashTranslationTest. |
| 11 | P1 | Headings, settings cards and options kept the old language until navigation. | Reactive translation collections in 15 locations; immediate-switch e2e regression. |
| 12 | P2 | Saved time preferences were ignored in timestamp displays. | Regional date/time helpers preserve calendar-day versus UTC-instant semantics; formatting tests. |
| 13 | P1 | Category/location errors were hidden or discarded after save. | useForm, preserved input, alert summary, invalid/described-by attributes and invalid-field focus. |
| 14 | P2 | Secondary/placeholder/no-image copy and destructive colors missed contrast targets. | Semantic token, placeholder, image and danger-button contrast fixes. |
| 15 | P2 | Legal links and flash dismiss controls were small pointer targets. | Minimum 24px targets and focus styling, keeping operational density. |
| 16 | P2 | Definition-list grouping was invalid; activity scroller was not keyboard reachable. | Correct DL groups; name/focus the user activity region. |
| 17 | P2 | Focus indicators and reduced motion were inconsistent. | Shared focus outlines and reduced-motion CSS. |
| 18 | P1 | Work-order links had blank numbers. | WorkOrders/Index reads `work_order_number`. |
| 19 | P2 | Long German/Dutch/Russian dashboard text widened phone layouts. | Wrapping headings/actions and breakable content. |
| 20 | P1 | Polish report actions and real return forms overflowed 320px. | Wrap report headers; stack return controls/footer; shared Button merges override classes via cn. |
| 21 | P2 | Sales chart widened the document and showed raw statuses. | Bounded internal scroller and localized status labels. |
| 22 | P2 | Pagination could fall outside expanded tables; portal product names squeezed to one word per line. | Shared Pagination/TableViewport footer, current/disabled semantics, readable minimum widths and internal scrolling for portal detail/forms. |
| 23 | P2 | Numeric headings conflicted with start alignment; PO item count said quantity. | Explicit end/center table alignment, tabular cells, correct heading and browser regression. |
| 24 | P2 | Movement types were technical values; zero adjustment looked like a decrease. | Localized type labels and neutral zero-change timeline. |
| 25 | P2 | Notification intervals accumulated across Inertia remounts. | Unmount cleanup; clock-driven regression verifies one poll after navigation. |
| 26 | P2 | Fresh-install titles said Laravel; auth heading semantics were missing. | Inventoros defaults and localized auth headings; existing configured APP_NAME is preserved. |
| 27 | P2 | Demo order/PO totals lacked supporting lines and customer links. | Deterministic linked fixtures, exact totals/cost snapshots and consistency regression. |
| 28 | P1 | Workspace menus/drawer lacked consistent keyboard dismissal/focus; open menus left partially covered targets clickable. | Shared disclosure keys, selection semantics, click-away layers, drawer containment/Escape restoration and regressions. |
| 29 | P2 | Optional plugins and exception/provider diagnostics contain English outside shared catalogs. | **Remaining:** localize plugin copy/tax terminology through scoped i18n and review user-facing diagnostic fallbacks. |
| 30 | P2 | Eager application bundle is about 2.65MB minified / 790KB gzip. | **Remaining:** split locale/runtime payloads after measuring throttled first-interaction performance. |
| 31 | P1 | Direct permission-denied visits showed a plain framework page without recovery navigation; refreshing could make Back loop. | Browser staff 403s use Errors/403 consistently; sanitize previous URL to a same-origin noncurrent fallback. JSON/API/portal behavior is preserved; PHP and browser regressions cover recovery. |

## Verification results

- [Baseline sweep](https://github.com/Inventoros/Inventoros/actions/runs/41477295273): 931 checks, 386 screenshots, five document overflows and nine axe rule categories. Guest contexts inherited staff authentication; that portion was rejected as guest verification.
- [Corrected sweep](https://github.com/Inventoros/Inventoros/actions/runs/41483326963): 938 checks, isolated guests, draft edit fixtures and actual return form. Residual labels/contrast and narrow layouts were subsequently fixed. Registration was disabled by configuration; later disposable runs explicitly enable it.
- Superseded partial strict runs were cancelled after newer code replaced them. Their return-footer finding led to issue 20's final fix. Cancelled/observation runs are not sign-off.
- Final strict runs assert HTTP status, uncaught JavaScript exceptions, document overflow and axe violations. Expected permission denial is separately asserted as 403. No axe rule is disabled. The open warehouse-menu scan covers its foreground menu: full-document axe reports small slivers of underlying buttons even though the click-away layer blocks their activation. Closed pages, dialogs and other states retain full-document scans. Click-away and keyboard regressions separately verify dismissal/restored access; earlier full-page findings remain in linked artifacts.
- Local JS: **205 passed**, including all 14 catalogs and regional formatting. Production Vite build passes; existing bundle-size warning is issue 30.
- Local targeted PHP: **26 passed / 7,182 assertions** for translation, MFA, account, roles and report scheduling before the final guard; final catalog guard **4 passed / 7,077 assertions**; fixture consistency separately passed. PHP 8.5 reports 12 existing PHPUnit metadata deprecations; CI uses PHP 8.4.
- [Dialog CI](https://github.com/Inventoros/Inventoros/actions/runs/41489479543): 79 e2e passed and six backend checks passed, including MySQL/PostgreSQL. [Reactive locale CI](https://github.com/Inventoros/Inventoros/actions/runs/41493988004): 81 e2e passed with no flaky result.
- No standalone lint/typecheck script exists in package.json. Vue compilation, JS tests, PHP checks, Playwright discovery and required CI checks are used.
- [Screenshot refresh](https://github.com/Inventoros/Inventoros/actions/runs/41495555350): **22 images**, 11 light/dark views including full-table products at 1920x1080. All 22 reviewed in contact sheets; dashboard/products/orders/full products also inspected at full size. A fixture-only refresh is pending.

Manual browser checks include Arabic shell geometry, French headings without navigation, 320x568 API-token initial/Shift-Tab/Escape focus, category validation/input preservation, dark product density, and corrected 320px return controls/footer with an item selected. Seventeen baseline contact sheets cover the primary viewport across staff modules; that does not mean every pixel of every long page was read. Raw portal and final conditional-state evidence are reviewed separately.

## Remaining work and verification limits

Known product work is P2: remaining plugin/diagnostic localization and initial bundle size. No P0 defect is known. P1 closure awaits final regression evidence.

Native screen readers, native-speaker review in all languages, physical iOS/Android devices, Firefox/Safari and every provider/permission combination were not tested. Catalog parity does not establish linguistic quality. Real email delivery, paid labels, marketplace transactions, invoice issuance and production installation/update flows were not exercised. Backend permission/failure tests are not claimed as visual verification.

## Recommendations

1. Retain the strict route/viewport sweep as a release gate and review its artifacts alongside functional e2e.
2. Localize optional plugin interfaces with scoped i18n and add their catalogs to parity checks.
3. Load locales on demand, then measure cold navigation/first interaction under throttling.
4. Add native screen-reader and native-speaker acceptance, prioritizing Arabic and longer German/Polish/Russian text.

Criteria: [WCAG 2.2](https://www.w3.org/TR/WCAG22/) and [axe documentation](https://github.com/dequelabs/axe-core/blob/develop/doc/API.md). This targets practical AA improvements, not certified conformance.
