# Inventoros UI/UX audit

Status: discovery and baseline verification in progress. This document distinguishes source review, automated browser checks and manual visual inspection; an inventoried screen is not necessarily verified.

## Executive summary

The approved light/dark branding and full-height operational table workspace remain the design direction. This pass audits the entire first-party application, including staff, administrator, restricted staff, guest and customer portal experiences. No production records are used.

## Application and checklist

- Laravel 13, Inertia 3, Vue 3, Tailwind 4, Lucide icons and vue-i18n 11; Vite builds. Semantic light/dark tokens live in `resources/css/design-tokens.css`; shared layouts are AppLayout, GuestLayout and PortalLayout.
- Discovery found 123 Vue files under Pages (including five partials), 64 reusable components, 83 files containing forms and 24 files containing dialog/teleport implementations. The 71 table templates use the shared TableViewport. Exact source/route inventory: [application map](docs/audits/ui-ux-application-map.json).
- Supported languages: en, es, fr, de, pt-BR, it, ja, ko, zh-CN, ar, ru, nl, tr, pl. Both frontend messages and backend validation/auth/pagination translations are in scope.
- [x] Inventory routes, templates, shared components, design tokens, languages and existing tests.
- [ ] Verify every accessible first-party page and record blocked or fixture-dependent states.
- [ ] Inspect layout at 320, 375, 430, 768, 1024, 1440 and 1920 pixels.
- [ ] Audit dialogs, navigation, dropdowns, forms, feedback, loading, errors and keyboard focus.
- [ ] Audit language switching, direction, missing keys, placeholders, plural messages and regional formatting.
- [ ] Audit contrast, labels, names, semantics, reduced motion and touch targets against practical WCAG 2.2 AA criteria.
- [ ] Verify table scrolling, full view, pagination, numeric alignment and editable/detail tables.
- [ ] Implement shared fixes, add important regression coverage and rerun affected journeys.
- [ ] Refresh and inspect screenshots, run build/tests, and finalize issue counts and limitations.

## Coverage

Baseline screenshots regenerated in [successful CI run](https://github.com/Inventoros/Inventoros/actions/runs/41474780161): 11 views in light and dark at 1920×1080, including full-window products and customers. These are baseline evidence, not a claim of full audit coverage.

## Issues and fixes

Pending rendered verification and implementation. Findings will be numbered with severity, evidence, affected files and verification status.

## Remaining issues and limitations

The audit is in progress. Native screen-reader testing and professional linguistic validation in every language have not been performed. Automated accessibility checks cannot establish full WCAG conformance.

## Verification and references

Existing Node tests validate locale key/placeholder/plural parity and message compilation; Playwright covers operational flows, permissions and mobile layouts; PHPUnit covers backend behavior across SQLite, MySQL and PostgreSQL in CI. This pass adds a repeatable rendered route/viewport/accessibility sweep using the existing Playwright infrastructure and a development-only axe integration.

Criteria reference: [W3C WCAG 2.2](https://www.w3.org/TR/WCAG22/). Automated checks use [Deque axe](https://github.com/dequelabs/axe-core/blob/develop/doc/API.md), alongside manual visual and keyboard checks.

## Recommendations

Pending completion of the verification pass.
