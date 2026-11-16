# Shared feature sections

Before: main at `c69ffb56`. After: this PR. Actual Chromium captures against the same isolated demo organization and user. Phone: 375 x 812; desktop/dark: 1440 x 1000.

Section names wrap only when needed, so Organization Settings exposes every section on phones while short Import/Export labels stay in one row. Organization Settings, Import/Export, Approvals, and plugin detail sections now share `SectionTabs` and `SectionPanel`. Ordinary links to other URLs retain link semantics.

The API is documented in `resources/js/Components/ui/SectionTabs.md`. The components own unique tab/panel IDs, roving keyboard focus, RTL arrows, Home/End, and selection recovery. Pages own permissions, data, and form state. Form panels stay mounted; plugin content keeps its existing lazy mounting.

Validation: production build, 226 JavaScript checks, 19 new browser checks, and 10 existing plugin/workspace checks. Coverage includes 320/375/768/1440 widths, field and file retention, approval notes/counts, permission-filtered sections, forbidden requested tabs, Arabic, dark/high contrast, scoped axe, and runtime plugin navigation under the production CSP.

PNGs here are before/after PR review evidence.
