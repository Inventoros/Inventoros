# Inventoros UI/UX, design and localization audit

Audit date: 2 November 2026. **Application-wide verification completed**, with the limitations below. Counts use root causes, not affected DOM nodes or translated strings.

## Executive summary

The approved identity is retained: restrained blue actions, semantic light/dark surfaces, compact navigation and operational tables filling the workspace. The main defects were dialog focus, unnamed controls, mixed-language feedback, incomplete Arabic direction, stale translated labels, narrow-screen overflow and table alignment. The register contains **31 findings: 29 fixed and two remaining P2 items**. No known P0/P1 issue remains in the verified scope. The full sweep recorded 1,164 checks, 544 screenshots and 246 axe scans with no unexpected HTTP responses, JavaScript exceptions, document overflows or scoped accessibility findings. Supported-language verification was independently rerun with explicit language/direction assertions.

## Application discovery and audit checklist

Laravel 13, Inertia 3, Vue 3, Tailwind 4, Lucide, vue-i18n 11 and Vite form the stack. AppLayout, GuestLayout and PortalLayout share semantic tokens in `resources/css/design-tokens.css`, `dashboard.css` and `tables.css`. The existing Inter/Figtree/system font stack, compact 13px operational text, blue actions and semantic borders/surfaces are preserved.

The [application map](docs/audits/ui-ux-application-map.json) inventories 123 page Vue files: 118 top-level templates and five embedded partials. Initial discovery counted 64 reusable Vue components, 83 form sources, 24 dialog/teleport sources and 71 table templates in 61 files. There are now 66 components after adding DialogSurface and Pagination. The normalized map has 119 route candidates, including one API route sharing a controller. An inventoried template is not automatically a visually verified screen.

- [x] Map routes, templates, roles, shared components, conventions and localization files.
- [x] Review spacing, typography, colors, states and table architecture in source and screenshots.
- [x] Run baseline and corrected browser sweeps across all seven requested widths.
- [x] Implement shared dialog, keyboard, form, translation, responsive and contrast fixes.
- [x] Exercise full-table view, filters, pagination, editable cells and focus through regression tests.
- [x] Check every supported frontend locale for keys, placeholders, plurals and message compilation.
- [x] Review final strict sweep, conditional states and optional plugin evidence.
- [x] Record passing CI results and refresh screenshots after fixture changes.

## Audit coverage

### Routes and states

Staff coverage includes dashboard; product index/create/edit/detail/reset preview/reset status; variant replenishment; categories/locations; warehouses/balances; stock adjustments, transfers, reservations, audits/count history/cycle schedules; work orders; customers; orders/payments; returns/order selection; purchase orders/receiving; approvals; import/export; every report/report builder; users, roles, activity, plugins, updates; profile and all settings sections. Embedded forms and overlays were inspected in source; route navigation alone is not claimed as interaction coverage.

The browser harness seeds 99 staff URL states. The verified administrator's `/verify-email` redirects; its actual prompt is separately checked using an unverified account. Guest checks cover login, enabled registration, password request/reset, portal login/request/reset and signed invitation. Portal checks cover dashboard, order index/detail/return form and return index/detail. Separate contexts render email verification, two-factor challenge and restricted-member 403. Installer welcome, requirements, database, administrator and completion pages are rendered by toggling only the disposable database's installation flag. No real installation or administrator-account submission occurs.

The optional plugin sweep activates Hello World and Tax Billing only in its disposable database, checks their pages and representative embedded components, then deactivates them. Issuing invoices, sending messages, buying labels, installing updates and destructive production actions are excluded.

### Viewports, themes and languages

Widths: **320, 375, 430, 768, 1024, 1440, 1920**. Height is 812 below tablet and 1080 otherwise; tall-dialog regressions also use 320x568. Audit screenshots are captured at 320/1440; the public gallery is 1920x1080. Dark-mode automation covers staff routes at 1440 and six layout families at 320. This is not every combination of route, viewport, theme and language.

All **14 languages** are covered by source/catalog checks: ar, de, en, es, fr, it, ja, ko, nl, pl, pt-BR, ru, tr, zh-CN. Each frontend catalog has **3,324 leaf keys**. Key/interpolation/plural parity and message compilation pass across all 14. The language browser sweep visits dashboard, product index/create, orders, account settings and reports at 320/1440. Arabic has an additional real browser assertion for RTL persistence and mirrored sidebar placement. French headings were manually verified to update and return to English without navigation.

Backend JSON catalogs contain **148 shared feedback/validation entries per locale**, with placeholder and encoding checks. Standard Laravel auth/password/pagination/validation catalogs remain in use. Internal protocol values, CSV/API identifiers and user data are preserved. Optional plugin-owned copy and exception/provider diagnostics outside these catalogs remain a translation gap; this is not a claim that every possible backend exception has been translated.

## Issues discovered and fixes implemented

Rows 01-28 and 31 are fixed and regression-verified. References name the main shared fix, not every affected page. Rows 29-30 remain open.

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
| 11 | P1 | Headings, settings cards and options kept the old language until navigation. | Reactive translation collections in 16 locations; immediate-switch e2e regression. |
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

Persistent counts, run links and scope notes: [verification ledger](docs/audits/ui-ux-verification.json). The main affected areas are [DialogSurface](resources/js/Components/DialogSurface.vue), [TableViewport](resources/js/Components/ui/TableViewport.vue), [Button](resources/js/Components/ui/Button.vue), [Pagination](resources/js/Components/ui/Pagination.vue), [AppLayout](resources/js/Layouts/AppLayout.vue), [shared styles](resources/css/design-tokens.css), [date helpers](resources/js/lib/dates.js), [frontend catalogs](resources/js/i18n/locales), [server catalogs](lang), controller feedback and the audit/regression tests under `e2e/tests` and `tests/Feature`.

- [Baseline sweep](https://github.com/Inventoros/Inventoros/actions/runs/41477295273): 931 checks, 386 screenshots, five document overflows and nine axe rule categories. Guest contexts inherited staff authentication; that portion was rejected as guest verification.
- [Corrected sweep](https://github.com/Inventoros/Inventoros/actions/runs/41483326963): 938 checks, isolated guests, draft edit fixtures and actual return form. Residual labels/contrast and narrow layouts were subsequently fixed. Registration was disabled by configuration; later disposable runs explicitly enable it.
- Superseded partial strict runs were cancelled after newer code replaced them. Their return-footer finding led to issue 20's final fix. Cancelled/observation runs are not sign-off.
- [Full passing sweep](https://github.com/Inventoros/Inventoros/actions/runs/41502431909): **1,164 rendered checks, 544 screenshots, 246 axe scans**; zero unexpected HTTP responses, uncaught JavaScript exceptions, document overflows or scoped axe findings. Eight read-only route/theme tests run concurrently; locale/plugin/installer mutations run afterward in one sequential phase.
- Coverage review found one Dutch request measured in Russian before preference persistence completed. The harness now waits for the save response and asserts lang/dir on every language check. [Strict language verification](https://github.com/Inventoros/Inventoros/actions/runs/41505431538) passed **168 checks: exactly 12 per language**, with zero overflow/exceptions. Application code did not change between these verification runs.
- [Focused interaction verification](https://github.com/Inventoros/Inventoros/actions/runs/41502425404): **100 checks, 45 screenshots, 34 axe scans**, with no findings; covers dialogs, menus, drawer, full table view, plugins, authentication, permission recovery and installer states.
- Final strict runs assert HTTP status, uncaught JavaScript exceptions, document overflow and axe violations. Expected permission denial is separately asserted as 403. No axe rule is disabled. The open warehouse-menu scan covers its foreground menu: full-document axe reports small slivers of underlying buttons even though the click-away layer blocks their activation. Closed pages, dialogs and other states retain full-document scans. Click-away and keyboard regressions separately verify dismissal/restored access; earlier full-page findings remain in linked artifacts.
- Local JS: **205 passed**, including all 14 catalogs and regional formatting. Production Vite build passes; existing bundle-size warning is issue 30.
- Local targeted PHP: **144 passed / 8,653 assertions** across feedback catalogs, payments, stock audits, permission recovery and fixture consistency; catalog guards independently passed **5 tests / 7,702 assertions**.
- [Required backend CI](https://github.com/Inventoros/Inventoros/actions/runs/41502425546): all six checks pass. SQLite: **3,942 passed / 28,358 assertions**, six driver-specific skips. MySQL: **3,947 passed / 28,404 assertions**, one SQLite-only skip. PostgreSQL: **3,947 passed / 28,405 assertions**, one SQLite-only skip. Existing PHPUnit metadata deprecations are reported; no failures. JS unit, dependency audit and production-install checks pass.
- [Functional e2e CI](https://github.com/Inventoros/Inventoros/actions/runs/41502425572): **87 passed**, no flaky result. Covers operational workflows and new focus, language, polling, table alignment, mobile portal and permission-recovery regressions.
- No standalone lint/typecheck script exists in package.json. Vue compilation, JS tests, PHP checks, Playwright discovery and required CI checks are used.
- [Final public screenshot refresh](https://github.com/Inventoros/Inventoros/actions/runs/41497379156): **22 images**, 11 light/dark views including full-table products at 1920x1080. Reviewed in contact sheets and selected full-size images. [Before/after comparisons](docs/audits/ui-ux/README.md) show permission recovery and the mobile return form.

Manual browser checks include Arabic shell geometry, French headings without navigation, 320x568 API-token initial/Shift-Tab/Escape focus, category validation/input preservation, dark product density, corrected 320px return controls/footer with an item selected, drawer focus wrapping/restoration and restricted-user recovery back to Dashboard. Raw portal and authentication/installer captures were visually inspected. Seventeen baseline contact sheets cover primary staff viewports; every pixel of every long page or generated screenshot was not manually read.

The [live desktop capture](docs/audits/ui-ux/workspace-after.png) is 1920x911: the document has zero horizontal/vertical overflow, while the 724px table viewport scrolls 891px of row content. The final live preview recorded no browser-console errors.

## Remaining work and verification limits

Remaining severity counts: **P0: 0; P1: 0; P2: 2; P3: 0** in the findings register. The two P2 items are plugin/diagnostic localization and initial bundle size. This is not a claim of exhaustive state coverage or certified accessibility.

Native screen readers, native-speaker review in all languages, physical iOS/Android devices, Firefox/Safari and every provider/permission combination were not tested. Catalog parity does not establish linguistic quality. All conditional tab/modal branches, expired invitation/session states, credential issuance/activation and provider failures were not exhaustively exercised visually. Real email delivery, paid labels, marketplace transactions, invoice issuance and production installation/update flows were not exercised. Backend permission/failure tests are not claimed as visual verification.

## Recommendations

1. Retain the strict route/viewport sweep as a release gate and review its artifacts alongside functional e2e.
2. Localize optional plugin interfaces with scoped i18n and add their catalogs to parity checks.
3. Load locales on demand, then measure cold navigation/first interaction under throttling.
4. Add native screen-reader and native-speaker acceptance, prioritizing Arabic and longer German/Polish/Russian text.

Criteria: [WCAG 2.2](https://www.w3.org/TR/WCAG22/) and [axe documentation](https://github.com/dequelabs/axe-core/blob/develop/doc/API.md). This targets practical AA improvements, not certified conformance.
