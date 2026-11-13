# README redesign: Inventoros and the Escalated portfolio

Date: 2026-11-12
Status: reviewed, awaiting approval

## Intent

The GitHub READMEs for Inventoros and the public Escalated repos should look like an established
product's: the reference points are Dub, Cal.com and Ghostfolio. Each README serves two readers
at once: a developer deciding whether to install it, and a buyer or evaluator judging whether the
product is credible. For both, the README must stay **simple and visual**. It shows the
product, says what it does in a few short lines, and links out to the docs for anything detailed.

## Goals

- A recognisable, consistent look across every public repo in both orgs, so each repo reads as
  part of the same product.
- Screenshots and demo GIFs do most of the explaining; text is one line per idea.
- Every README answers, above the fold: what is this, what does it look like, where do I go next.
- No existing information is lost: long-form README content moves into `docs/` and is linked.

## Non-goals

- No change to the docs sites (`escalated.dev/docs`, `inventoros.com/docs`) or marketing sites.
- No README generator or shared template engine (approach C was rejected; revisit once the format
  settles).
- Private repos are out of scope (cloud, desktop, app, private plugins, `*.com` / `*.dev` sites,
  context repos marked private).

## Anti-goals (a failure even if it ships)

- A wall of badges, a feature list longer than a screen, or install docs inlined in the README.
- Copy that reads machine-written: hype words, emoji bullets, em dashes, "seamless/powerful/robust".
  Follow the portfolio voice rules (plain, second person, honest; see `seo-research/VOICE.md`).
- Claims the code does not back up. Every feature row must match shipped behaviour.
- Screenshots that drift from the real UI or show fake-looking data.
- Broken relative links after content moves into `docs/`.

## Approaches considered

| | Approach | Trade-off | Outcome |
|---|---|---|---|
| A | Hand-polish each README using existing assets only | Fast, but plugins have no visuals and 35 repos drift apart | Rejected |
| **B** | **Shared visual kit (header, screenshot set) rolled out in tiers** | **Consistent and visual; costs a screenshot pipeline and per-repo writing** | **Chosen** |
| C | B plus a generator that renders every README from one template | Guaranteed consistency; repos lose individual voice, generator to maintain | Deferred |

Visual direction: **A, "Product-first" (Dub direction)**, chosen over a gradient banner (Cal.com)
and a classic checklist layout (Ghostfolio). Mockup: brainstorm screen `readme-directions.html`.

## The layout (decided)

```
            [logo]
           <Name>
     <one-sentence description>
   Website · Docs · Live demo · <one more>

 ┌──────────── hero screenshot or demo GIF ───────────┐
 └────────────────────────────────────────────────────┘

 ## What it does        3-4 rows, alternating text|image and image|text,
                        each: a bold one-line heading + one muted sentence
 ## Quick start         at most 5 lines of commands
 ## Learn more          link list into docs/ and the docs site
 (footer)               license line, contributing link
```

- Centred header in HTML (`<p align="center">`), logo 64-96px, name as `<h1>`.
- The link row only links things that exist. Neither product has a hosted demo today, so
  there is no "Live demo" link (*builder's call from review*). Default row: Website · Docs ·
  Install · Changelog for Inventoros; Website · Docs · Cloud · Changelog for Escalated. Standing
  up a hosted demo is a separate project.
- A **link row replaces badges**. At most one small status line (CI + license) is allowed under
  the link row only if the repo already relies on it; default is none.
- Screenshots use `<picture>` with `prefers-color-scheme` sources so GitHub light/dark visitors
  each see the matching theme.
- Feature rows use a two-column HTML table without borders (GitHub strips CSS) or stacked
  image-then-text on repos where the table renders poorly; the builder picks per repo after
  checking the rendered result on github.com.

## Tiers (decided)

**Tier 1, full treatment:** `Inventoros/Inventoros`, `escalated-dev/escalated`,
`escalated-laravel`, `escalated-nestjs`, `escalated-rails`, `escalated-django`, plus the org
profile READMEs `Inventoros/.github` and `escalated-dev/.github` (`profile/README.md`).

**Tier 2, same header and hero, two feature rows, framework quick start:** `escalated-adonis`,
`escalated-symfony`, `escalated-wordpress`, `escalated-phoenix`, `escalated-go`, `escalated-dotnet`,
`escalated-spring`, `escalated-filament`, `escalated-flutter`, `escalated-react-native`,
`escalated-plugin-sdk`, `escalated-plugin-runtime`, `Inventoros/plugin-shopify`. Header reads
"Escalated for <Framework>" so each is distinct.

*Builder's calls (from review):*
- Repos that don't render the shared web UI never reuse the web screenshots. `escalated-flutter`
  and `escalated-react-native` use real mobile screenshots if their example app can be run;
  otherwise they take no hero. `escalated-plugin-sdk`, `escalated-plugin-runtime` and
  `plugin-shopify` use a short code snippet (or the Shopify connection screen if one exists) as
  the hero.
- Feature rows on each backend only claim what that port ships, checked against the backend
  parity notes. The hero uses the agent inbox / ticket view, which every port has. Screens that
  not every port supports (for example Automations) appear only in READMEs of ports that ship them.

**Tier 3, small header, two sentences, link back to the main repo, no screenshots:**
`escalated-contract`, `escalated-locale`, `escalated-developer-context`, `escalated-docs`, and the
public `escalated-plugin-*` repos (community, import-freshdesk, import-helpscout, import-intercom,
import-zendesk, jira, marketplace, nps, slack, web-widget). A plugin with a visible UI may take one
screenshot if one already exists.

## Visual kit (decided)

- **Escalated screenshots** come from the existing Storybook full-page stories captured by
  `escalated/screenshots/components.spec.js` (admin dashboard, agent panel, light and dark). Add
  stories as needed for: ticket inbox, ticket detail with reply, customer portal, SLA view. One
  shared set serves every backend, since all backends render the same frontend.
- **Inventoros screenshots** come from `ScreenshotSeeder` + `e2e/tests/screenshots.spec.ts`; the
  existing `.github/demo/*.gif` (from `scripts/generate-demo.js`) are reused as the hero.
- Screenshots are stored in the repo that owns the UI (`escalated/.github/readme/`,
  `Inventoros/.github/readme/`) and other repos reference them by absolute
  `raw.githubusercontent.com` URL pinned to a tag or commit, so 13 backends don't each carry copies.
- Captured at one consistent viewport (1440x900, device scale 2), cropped to content, PNG
  optimised; GIFs under 5 MB.

## Content moves (decided)

- Everything removed from a README moves to `docs/` in that repo (e.g. `docs/installation.md`,
  `docs/configuration.md`), moved verbatim rather than rewritten, with relative links fixed.
- The "Learn more" list links each moved file plus the matching page on the docs site.

## Translations (decided)

`escalated` keeps its 13 translated READMEs in `docs/translations/` and regenerates them from the
new short English README. They are already stale (last updated 2026-04-30; English changed
2026-09-29). Product names, commands and code stay untranslated. The language switcher row stays
at the top, kept compact.

*Builder's call (from review):* the same rule applies to every other repo that carries translated
READMEs (about 16 backend ports, including the `escalated-laravel` pilot): regenerate each set
from that repo's new English README in the same PR, so the switcher never points at stale
long-form READMEs whose links break after content moves into `docs/`. Translations are
machine-generated, then checked for: untranslated product names, commands and code; working
relative links; and no hype wording.

## Rollout (decided)

1. Pilot: `Inventoros/Inventoros` and `escalated-laravel` (plus the shared Escalated screenshot
   set it needs). The user approves the rendered result on github.com before anything else.
2. Remaining tier 1, then tier 2, then tier 3.
3. One PR per repo, from a branch off the default branch (several local checkouts sit on feature
   branches; use worktrees, don't disturb them).

## Constraints

- No "Claude" or "Filament" mentions in commit messages (Filament is fine in the
  `escalated-filament` README itself).
- Licence lines state exactly what each repo's LICENSE file says. Today the core, ports, SDK,
  runtime, contract, locale **and the existing public `escalated-plugin-*` repos are MIT**; only
  new plugins are AGPL, and relicensing the existing ones is a separate decision, not part of this
  work. Inventoros and `plugin-shopify` are AGPL v3.
- Quick-start commands must be verified against each repo's current install docs.
- GitHub README rendering limits: no CSS, no JS; `align`, `width`, `<picture>`, `<table>`,
  `<details>`, `<sub>` work.

## Left to the builder

- Exact one-sentence description and feature-row wording per repo (within the voice rules).
- Which 3-4 features each tier-1 repo leads with, chosen from what the code actually ships.
- The extra Storybook stories needed and their fixture data.
- Per-repo choice between table rows and stacked rows after checking the render.
- `docs/` file names for moved content.
