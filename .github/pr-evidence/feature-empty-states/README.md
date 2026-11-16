# Feature-list empty states

Before: main at `c69ffb56`. After: this PR. Screenshots are actual Chromium captures against the same isolated demo workspace and applied search. Phone captures use 375 x 812; desktop/dark use 1440 x 1000.

The empty state now belongs to the visible panel rather than a wide table cell. Search results offer recovery; unfiltered lists keep their feature-specific message and permitted create action. The shared pattern is used by Inventory, Orders, Purchase Orders, Customers, Suppliers, Returns, Stock Transfers, Stock Audits, Work Orders, and Warehouses.

The component API is documented in `resources/js/Components/ui/README.md`. Before/after PNGs here are PR review evidence.

Validation: production build, 226 JavaScript tests, and 37 browser checks (28 feature-empty-state checks plus 9 existing table-viewport checks). Recovery runs against real routes on all ten pages at 320 and 1440 pixels. Additional cases cover clearing Inventory's low-stock/category/location filters, read-only roles, full-table focus restoration, Arabic/dark/high-contrast, a 320-pixel-tall viewport, and scoped axe.
