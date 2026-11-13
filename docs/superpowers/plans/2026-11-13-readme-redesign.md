# README Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rewrite the READMEs of Inventoros and the public Escalated repos into the short, visual "product-first" layout from the spec.

**Architecture:** One shared Escalated screenshot set is captured from Storybook in `escalated` and referenced by pinned raw URLs from every backend. Inventoros reuses its Playwright screenshot set and demo GIFs. Each repo gets one PR: new README, long-form content moved verbatim into `docs/`, translations regenerated where they exist.

**Tech Stack:** GitHub-flavoured Markdown + HTML subset, Playwright, Storybook 10.

**Spec:** `docs/superpowers/specs/2026-11-12-readme-redesign-design.md` (in this repo)

## Global Constraints

- Layout: centred logo (64-96px) + `<h1>` name + one sentence + link row; hero image; "What it does" 3-4 rows (tier 1) or 2 rows (tier 2); "Quick start" at most 5 lines; "Learn more" links; licence line.
- Link row links only things that exist. Inventoros: Website · Docs · Install · Changelog. Escalated: Website · Docs · Cloud · Changelog.
- No badge walls, no emoji bullets, no em dashes, no "seamless/powerful/robust"; plain second-person copy (portfolio VOICE rules).
- Every feature row matches shipped behaviour of that repo.
- Screenshots: `<picture>` with `prefers-color-scheme: dark` source + light `<img>` fallback.
- Licence line states the repo's LICENSE file exactly.
- No "Claude" or "Filament" in commit messages.
- One branch `docs/readme-redesign` per repo, from `origin/<default>`, in a worktree; never disturb existing local checkouts.
- Removed README content moves verbatim into `docs/`; relative links fixed.
- Translated READMEs (13 languages in `docs/translations/`) are regenerated from the new English README in the same PR; product names, commands, code, URLs untranslated.

## Review Focus

- Relative links after moves: every `](docs/...)` and `](../...)` in README and translations resolves to a file in the branch.
- Dark/light: each `<picture>` has both sources and the images exist at the pinned URL.
- Feature claims per backend: no row for a feature that port lacks (check `reference_escalated_backend_parity`).
- Translations: switcher row links resolve from both README.md and `docs/translations/*` (paths differ by depth).
- GitHub render: tables and `<picture>` render on github.com (verify via `gh api repos/<r>/readme` rendered HTML or the PR's rich diff).

A link checker run per repo covers the first and fourth: `node scripts` is not needed; use
`npx --yes markdown-link-check -q README.md docs/translations/*.md` (relative links only; config ignores `http`).

---

### Task 1: Shared Escalated README screenshot set

**Files:**
- Modify: `escalated/screenshots/components.spec.js` (add `README set` block)
- Create: `escalated/src/pages/Customer/Index.stories.js` (portal story, `light` and `dark`, fixture tickets)
- Create: `escalated/docs/assets/readme/{inbox,ticket,dashboard,portal}-{light,dark}.png`, `escalated/docs/assets/readme/logo.png`

**Interfaces:**
- Produces: image URLs `https://raw.githubusercontent.com/escalated-dev/escalated/<sha>/docs/assets/readme/<name>.png`, consumed by every Escalated README.

- [ ] Step 1: Add the portal story; reuse fixture style from `.storybook/agentFixtures.js`.
- [ ] Step 2: Add tests writing the 8 PNGs at viewport 1440x900, deviceScaleFactor 2; agent shots wait for `[data-testid="esc-queue-row"]`.
- [ ] Step 3: Run `npm run screenshot -- -g "README set"`; expected 8 passed and 8 files present.
- [ ] Step 4: Copy logo (from escalated.dev assets) to `docs/assets/readme/logo.png`.
- [ ] Step 5: Commit, PR, merge after CI; record merge SHA for pinning.

### Task 2: `escalated` README (tier 1)

- [ ] Write README per layout. Hero `inbox`; rows: ticket view, admin dashboard/SLA, customer portal, embeddable widget (existing `demo.gif`). Quick start: point to backend packages (this package is not installed directly).
- [ ] Move removed sections verbatim to `docs/` files; "Learn more" links them.
- [ ] Regenerate 13 translations.
- [ ] Link check passes; commit; PR; merge after CI.

### Task 3: `escalated-laravel` README (pilot backend)

- [ ] Header "Escalated for Laravel"; hero `inbox`; rows: ticket view, SLA and escalation (dashboard), customer portal, inbound email/widget (text row linking docs).
- [ ] Quick start: `composer require escalated-dev/escalated-laravel`, `npm install @escalated-dev/escalated`, `php artisan escalated:install`, `php artisan migrate`, then visit `/support`.
- [ ] Move sections (Requirements onward except Quick start) verbatim into existing/new `docs/*.md`; link from "Learn more".
- [ ] Regenerate 13 translations; link check; commit; PR; merge after CI.

### Task 4: `Inventoros` README

- [ ] Hero: `screenshots/dashboard{,-light}.png` in `<picture>`. Rows: low stock to purchase order (`.github/demo/receive-stock.gif`), orders to shipment (`order-to-ship.gif`), multi-location (`locations`), reports (`reports`).
- [ ] Quick start from current README "Quick Start", trimmed to 5 lines.
- [ ] Move Features/Tech stack/Requirements/Installation/Configuration/Testing/CLI/API into `docs/` verbatim.
- [ ] Link check; commit (include spec + plan); PR; merge after CI.

### Task 5: Remaining tier 1 (nestjs, rails, django, both org profiles)

Same as Task 3 with each framework's own quick start and parity-checked rows. Org profiles: header, hero, one line per product/repo group, links.

### Task 6: Tier 2 backends and SDKs

Header "Escalated for <Framework>", hero `inbox` (web backends only), 2 rows, quick start. Non-web repos per spec builder's calls (code snippet or mobile shots).

### Task 7: Tier 3 utility and plugin repos

Small header, two sentences, link to main repo/docs, licence line. No screenshots unless one already exists.
