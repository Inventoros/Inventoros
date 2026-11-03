# Inventoros UI/UX, design and localization audit

Audit date: 2 November 2026. **Application-wide verification completed**, with the limitations below. Counts use root causes, not affected DOM nodes or translated strings.

## Executive summary

The approved identity is retained: restrained blue actions, semantic light/dark surfaces, compact navigation and operational tables filling the workspace. The register now contains **38 findings: 37 fixed and one partially resolved P2 localization item**. The second pass reviewed nine findings, including two carried over from the first pass, and fixed eight. It addresses real notification responses, rejected actions, late plugin translations and language-loading performance. No known P0/P1 issue remains in the verified scope. Earlier passing runs remain historical evidence alongside the second-pass verification below.

## Application discovery and audit checklist

Laravel 13, Inertia 3, Vue 3, Tailwind 4, Lucide, vue-i18n 11 and Vite form the stack. AppLayout, GuestLayout and PortalLayout share semantic tokens in `resources/css/design-tokens.css`, `dashboard.css` and `tables.css`. The existing Inter/Figtree/system font stack, compact 13px operational text, blue actions and semantic borders/surfaces are preserved.

The [application map](docs/audits/ui-ux-application-map.json) inventories 123 page Vue files: 118 top-level templates and five embedded partials. Initial discovery counted 64 reusable Vue components, 83 form sources, 24 dialog/teleport sources and 71 table templates in 61 files. There are now 67 components after adding DialogSurface, Pagination and ActionError. The normalized map has 119 route candidates, including one API route sharing a controller. An inventoried template is not automatically a visually verified screen.

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

All **14 languages** are covered by source/catalog checks: ar, de, en, es, fr, it, ja, ko, nl, pl, pt-BR, ru, tr, zh-CN. Each frontend catalog now has **3,329 leaf keys**. Key/interpolation/plural parity and message compilation pass across all 14. The language browser sweep visits dashboard, product index/create, orders, account settings and reports at 320/1440. Arabic has an additional real browser assertion for RTL persistence and mirrored sidebar placement. French headings were manually verified to update and return to English without navigation.

Backend JSON catalogs contain **148 shared feedback/validation entries per locale**, with placeholder and encoding checks. Standard Laravel auth/password/pagination/validation catalogs remain in use. The two bundled plugins add **73 frontend keys**, and Tax Billing adds **16 backend messages and 19 validation labels**, in every supported language. Plugin catalog parity, interpolation, compilation and shipped-bundle rendering are tested. Internal protocol values, CSV/API identifiers, configured tax labels and immutable invoice snapshots are preserved. Fixed-English invoice PDF labels/fonts and exception/provider diagnostics outside these catalogs remain a translation gap.

## Issues discovered and fixes implemented

References name the main shared fix, not every affected page. Rows 01-31 describe the initial pass; rows 32-38 record the second pass. Final verification and remaining scope are listed below.

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
| 29 | P2 | Optional plugins and exception/provider diagnostics contain English outside shared catalogs. | **Partially resolved:** all bundled plugin UI, permission labels and tax validation/flash messages now cover 14 locales; tab/menu/widget labels react to late message registration. **Remaining:** invoice PDF labels/font support and exceptional provider diagnostics. |
| 30 | P2 | Eager application bundle is about 2.65MB minified / 790KB gzip; global speculative prefetch would download all split locales anyway. | English fallback remains eager; 13 other catalogs load on selection, with initial-load, race, merge, retry and failure handling. Disable broad speculative asset prefetch; test real deferred downloads. |
| 31 | P1 | Direct permission-denied visits showed a plain framework page without recovery navigation; refreshing could make Back loop. | Browser staff 403s use Errors/403 consistently; sanitize previous URL to a same-origin noncurrent fallback. JSON/API/portal behavior is preserved; PHP and browser regressions cover recovery. |
| 32 | P1 | Notification dropdown expected an ordinary JSON response from an Inertia endpoint; unread messages appeared empty and failed requests had no usable feedback. | Read the actual partial Inertia payload, retain records/count on failures, announce retryable errors, cancel unmounted requests and coalesce overlapping loads/writes. |
| 33 | P1 | Notification details launched competing GET/POST requests, risking a cancelled read update or duplicate destination load. | Follow the existing server redirect through one guarded Inertia POST; verify the write precedes one destination request. |
| 34 | P1 | Rejected user/role/order deletion and order approval closed the confirmation and hid validation; review notes could disappear. | useForm closes only on success; shared ActionError announces/focuses the reason inside the open dialog. Notes survive rejection; duplicate submissions are guarded. |
| 35 | P2 | Self-deletion was offered despite server rejection; user/role delete buttons ignored the delete permission. | Match existing permission gates; disable self-delete with a translated visible explanation. Server authorization remains authoritative. |
| 36 | P2 | Permission search's scroll region was called Activity Log and provided no feedback for no matches. | Correct region name and localized status for empty search results. |
| 37 | P2 | Parent opacity and pale timestamps reduced notification text below AA contrast despite semantic text colors. | Remove read-row opacity and use readable timestamp colors while retaining read/unread indicators. |
| 38 | P2 | Notification click-away layer covered the bell, preventing pointer dismissal/reopening during loading. | Keep the open bell above its backdrop; exercise actual repeated pointer clicks while a request is delayed. |

## Verification results

### Second pass

Implementation commits: `a2baf46e`, `d5b9cb02`, `342e5e05`. The first new functional run exposed the bell/backdrop defect, speculative locale downloads and an ambiguous status assertion; these were fixed rather than retried away. Superseded runs were cancelled. No test was removed or weakened to accept a broken interaction.

- [Functional browser CI](https://github.com/Inventoros/Inventoros/actions/runs/41562021067): **101 passed**, no flaky result. Four new action-feedback, seven notification-feedback and three real locale-download tests cover rejected updates, input preservation, overlap/coalescing, one destination request, genuine retry, initial RTL, cached languages and no unselected-language downloads.
- Local **224 JS tests pass**, including 14-language core/plugin parity, shipped plugin bundle rendering and real Vue reactivity after late catalog registration. The targeted PHP pass contains **46 tests / 302 assertions** for notifications, plugin metadata, namespace boundaries and tax billing; locale preference tests separately pass **9 tests / 60 assertions**.
- [Backend/database CI](https://github.com/Inventoros/Inventoros/actions/runs/41562021075) runs SQLite, MySQL and PostgreSQL plus JS, production installation and dependency checks. Its job logs contain the final totals.
- [Second full strict rendered sweep](https://github.com/Inventoros/Inventoros/actions/runs/41562022628) retains all seven viewports, guest/portal/authentication/installer states, dark screens and core language checks. It adds **224 plugin locale records** across all 14 languages at 320/1440: plugin pages, visible navigation, banner/widget, selected product tab, order action and customer field. Each record asserts language/direction and real text against the relevant catalog. Uploaded `summary.json` and screenshots are the measured evidence.
- Production Vite build passes. The application entry is **637,316 bytes / 210,657 gzip**, versus **2,650,125 / 790,465** before the split: **76.0% less minified code, 73.4% less compressed initial JavaScript**. A non-English startup additionally loads its selected catalog. This is an asset-size comparison, not a measured Core Web Vitals claim. Global speculative prefetch is disabled so the bandwidth saving survives startup; page chunks still load on navigation. Vite's advisory 500KB entry warning remains.
- PHP syntax checks, affected plugin/service Pint checks, Vue compilation and diff checks pass. AppServiceProvider has existing whole-file Pint formatting findings outside its removed prefetch/import lines; they were not broadly rewritten. No standalone lint/typecheck script exists.
- Manual Chrome verification covers real unread records; repeated bell toggles; French switching; Arabic plugin page, tax settings, selected product tab and visible drawer labels after a fresh load; self-delete explanation; permission-search empty feedback; and the named mobile delete confirmation. [New before/after evidence](docs/audits/ui-ux/README.md) is retained. The final 1920x911 preview has zero document overflow and a 724px table scrolling 891px of content; browser-console error log is empty.

### First-pass baseline and historical verification

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

### Release refinement: legal footer

The requested footer placement is included in the 2.1.0 candidate. Primary
table workspaces put legal links on one line below pagination/actions, and
expanded tables include the same footer after their control row. Sidebar legal
text is removed on these pages. Other page/authentication/portal footers retain
their existing behavior. The row keeps 12px text, keyboard-reachable links and
its own narrow-screen horizontal scrolling.

The [new desktop capture](docs/audits/ui-ux/table-footer-after.png) has zero
document overflow at 1920x911: a 33px footer leaves a 691px independently
scrolling table. [Mobile expanded-table verification](docs/audits/ui-ux/table-footer-mobile-after.png)
uses 320x568. Four browser regressions cover placement, single-line geometry,
fixed controls, keyboard access and expanded embedded tables; navigation focus
now wraps to Log Out because the legal links are outside the drawer.

## Remaining work and verification limits

Remaining severity counts: **P0: 0; P1: 0; P2: 1; P3: 0** in the findings register. The partially resolved item is localization outside the translated UI catalogs: invoice PDF labels and Arabic/CJK font/layout support, plus exceptional provider diagnostics. Existing configured tax labels, historical notification text and immutable invoice snapshots retain their recorded values. This is not a claim of exhaustive state coverage or certified accessibility.

Native screen readers, native-speaker review in all languages, physical iOS/Android devices, Firefox/Safari and every provider/permission combination were not tested. Catalog parity does not establish linguistic quality. All conditional tab/modal branches, expired invitation/session states, credential issuance/activation and provider failures were not exhaustively exercised visually. Real email delivery, paid labels, marketplace transactions, invoice issuance and production installation/update flows were not exercised. Backend permission/failure tests are not claimed as visual verification.

## Recommendations

1. Retain the strict route/viewport sweep as a release gate and review its artifacts alongside functional e2e.
2. Extend localization to invoice exports with an explicit document-language choice and verified Arabic/CJK font/layout support; review exceptional diagnostic fallbacks.
3. Measure cold navigation/first interaction and Core Web Vitals under throttling after the verified locale split.
4. Add native screen-reader and native-speaker acceptance, prioritizing Arabic and longer German/Polish/Russian text.

Criteria: [WCAG 2.2](https://www.w3.org/TR/WCAG22/) and [axe documentation](https://github.com/dequelabs/axe-core/blob/develop/doc/API.md). This targets practical AA improvements, not certified conformance.
