# Reports pictograms

Thirteen report-specific SVG pictograms extend the existing blue/neutral palette. They share a 48-unit drawing grid, 1.75-unit stroke, square stock compartments, and a restrained blue detail. Utility and navigation icons keep the existing Lucide set.

The drawings describe the work: valuation pairs a stock register with a coin, low stock uses a minimum marker, locations use warehouses, and expiry uses batch labels and an hourglass. They are decorative illustrations, not charts of the current data. The report title remains the accessible link name.

## Screenshot provenance

Actual Chromium captures of `/reports` against the same isolated demo workspace, viewport, theme, and authenticated user:

- Before: main at `391c763a`.
- After: this PR.
- Desktop and dark: 1920 x 1080.
- Phone: 375 x 812; an additional full-page capture includes all thirteen cards.

Routes, permission filters, card order, descriptions, and the saved-report table are unchanged. No dashboard layout or metric styles change in this PR.

## Verification

- Production Vite build and 226 JavaScript tests pass.
- 43 report/controller and warehouse report-access tests pass (283 assertions).
- Browser QA at widths 320, 375, 768, 1024, 1440, 1920, and 3440: all thirteen cards fit, titles wrap, SVG artwork stays inside its view box, and there is no horizontal overflow or browser exception.
- Keyboard Tab reaches consecutive report links; Enter opens Inventory Valuation.
- Reports catalog has zero scoped WCAG A/AA axe violations in light, dark, forced-colors, and RTL layouts. Forced-colors uses CanvasText for the artwork.

Before/after PNGs live here solely as PR review evidence.
