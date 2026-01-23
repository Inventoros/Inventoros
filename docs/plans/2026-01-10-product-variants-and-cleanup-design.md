# Product Variants UI & Code Cleanup Design

**Date:** 2026-01-10
**Status:** Approved

## Overview

Complete the Product Variants frontend UI, add error logging to silent catch blocks, refactor large Vue components with reusable pieces, and complete quick wins (delete dead files, fix permission enum).

---

## Phase 1: Backend Updates

### 1.1 ProductController@show
- Eager load `variants` and `options` relationships
- Pass to Inertia view

### 1.2 ProductController@edit
- Pass `currencies` and `defaultCurrency` props (like Create does)
- Eager load `variants` and `options`

### 1.3 ProductController@update
- Accept `has_variants`, `options`, and `variants` in request
- Sync options: create new, update existing, delete removed
- Sync variants: create new, update existing, delete removed
- Wrap in database transaction

---

## Phase 2: Reusable Components

### 2.1 QuickAddModal.vue
- Generic modal wrapper for quick-add forms
- Props: `show`, `title`, `loading`
- Slots: `default`, `actions`
- Location: `resources/js/Components/QuickAddModal.vue`

### 2.2 SKUGeneratorModal.vue
- Extracted from Create/Edit pages
- Props: `show`, `productName`, `categoryId`
- Emits: `apply(sku)`, `close`
- Location: `resources/js/Components/SKUGeneratorModal.vue`

### 2.3 VariantStockAdjuster.vue
- Inline +/- buttons with popover
- Props: `variant`, `productId`
- Handles API call to adjust stock
- Location: `resources/js/Components/VariantStockAdjuster.vue`

### 2.4 VariantsTable.vue
- Display variants in table format
- Props: `variants`, `currencySymbol`, `editable`
- Columns: Variant, SKU, Price, Stock, Status
- Location: `resources/js/Components/VariantsTable.vue`

---

## Phase 3: Page Updates

### 3.1 Show.vue - Add Variants Display
- New "Product Variants" card after Pricing section
- Shows when `product.has_variants === true`
- Uses VariantsTable component
- Inline stock adjustment with VariantStockAdjuster
- Stock status badges (In Stock, Low Stock, Out of Stock)

### 3.2 Edit.vue - Add Variant Management
- Import ProductVariantManager component
- Load existing options/variants from props
- Add collapsible variants section (like Create page)
- Disable base stock field when has_variants is true
- Submit variants with form

### 3.3 Create.vue - Refactor
- Use QuickAddModal for Category/Location modals
- Use SKUGeneratorModal for SKU generation
- Reduce file size from ~957 to ~750 lines

---

## Phase 4: Code Quality - Error Logging

### Files to Update:
1. `app/Traits/LogsActivity.php` (lines 17-19, 38-40, 48-50)
   - Add `Log::warning()` in catch blocks

2. `app/Http/Middleware/CheckInstallation.php` (lines 39-42)
   - Add `Log::error()` for database errors

3. `app/Services/PluginService.php` (lines 68-71)
   - Add `Log::warning()` when plugins table doesn't exist

---

## Phase 5: Quick Wins

### 5.1 Delete Dead Files
- `resources/js/Pages/Dashboard.vue.backup`
- `nul` (root directory)

### 5.2 Fix Permission Enum
- Add `manage_stock` to `app/Enums/Permission.php`
- Currently used in routes but not defined

---

## File Impact Summary

| File | Change |
|------|--------|
| ProductController.php | Update show, edit, update methods |
| QuickAddModal.vue | New (~80 lines) |
| SKUGeneratorModal.vue | New (~150 lines) |
| VariantStockAdjuster.vue | New (~100 lines) |
| VariantsTable.vue | New (~120 lines) |
| Show.vue | Add variants section (+50 lines) |
| Edit.vue | Add variant manager, use components (-200 lines) |
| Create.vue | Use extracted components (-200 lines) |
| LogsActivity.php | Add error logging |
| CheckInstallation.php | Add error logging |
| PluginService.php | Add error logging |
| Permission.php | Add manage_stock |
| Dashboard.vue.backup | Delete |
| nul | Delete |
