# Barcode Scanning Integration Design

**Date:** 2026-02-04
**Status:** Approved
**Priority:** High

## Overview

Integrate existing barcode scanning components into Products and Stock Adjustments workflows to enable quick product lookup and selection via barcode scanning.

## Current State

- `BarcodeScanner.vue` component exists (uses html5-qrcode library)
- `BarcodeScannerModal.vue` component exists (camera + manual entry modes)
- Currently used in Purchase Orders workflows
- API endpoint `/api/v1/barcode/{code}` exists and functional
- Multi-tenant scoped, returns product with stock data

## Goals

1. Enable quick product lookup on Products Index page via barcode scanning
2. Enable product selection in Stock Adjustments Create via barcode scanning
3. Reuse existing components (no rewrites)
4. Maintain consistent UX across both contexts
5. Support keyboard shortcuts for power users

---

## Architecture Overview

### Components to Modify

**1. Products Index Page** (`resources/js/Pages/Products/Index.vue`)
- Add floating "Scan Barcode" button (bottom-right corner)
- Import and use existing `BarcodeScannerModal.vue`
- Handle `product-found` event to navigate to product Show page
- Add keyboard shortcut listener (Ctrl+B)

**2. Stock Adjustments Create Page** (`resources/js/Pages/StockAdjustments/Create.vue`)
- Add scan icon button next to product selection field
- Import and use existing `BarcodeScannerModal.vue`
- Handle `product-found` event to auto-populate product selection
- Focus next field after successful scan

### No Backend Changes Required

- Existing `/api/v1/barcode/{code}` endpoint handles all lookups
- Already authenticated and multi-tenant scoped
- Returns complete product data including stock levels

### Key Design Decisions

- **Reuse existing BarcodeScannerModal** - Already has camera/manual modes and error handling
- **Consistent UX** - Modal behavior identical in both contexts, only the result action differs
- **Minimal code changes** - Just integration points, no component rewrites
- **Keyboard shortcut: Ctrl+B** - Easy to remember (B for Barcode), works on Products Index

---

## Products Index Integration

### Floating Scan Button

**Visual Design:**
- Position: Fixed bottom-right corner (Floating Action Button pattern)
- Icon: Barcode icon from existing icon set
- Color: Primary blue (`bg-primary-400`) matching app theme
- Size: 56x56px desktop, 64x64px mobile (touch-friendly)
- Shadow: Elevated appearance with drop shadow
- Hover state: Darker blue with subtle scale animation
- Z-index: Above content, doesn't obstruct list items

**Behavior:**
1. Button click opens `BarcodeScannerModal`
2. Keyboard shortcut `Ctrl+B` also opens modal
3. Modal opens with camera mode by default (if permissions granted)
4. User scans barcode or switches to manual entry
5. On successful scan:
   - Modal shows "Product Found" with details (name, SKU, stock)
   - User clicks "Select Product" button
   - Page navigates to `/products/{id}` (Show page) via Inertia
   - Modal closes automatically on navigation
6. On error (product not found):
   - Modal shows error message: "No product found with this barcode or SKU"
   - User can try again, switch to manual entry, or close modal

**State Management:**
```javascript
const showScannerModal = ref(false);

const openScanner = () => {
  showScannerModal.value = true;
};

const closeScanner = () => {
  showScannerModal.value = false;
};

const handleProductFound = (product) => {
  router.visit(route('products.show', product.id));
};
```

**Keyboard Shortcut:**
```javascript
onMounted(() => {
  window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown);
});

const handleKeydown = (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
    e.preventDefault();
    showScannerModal.value = !showScannerModal.value; // Toggle
  }
};
```

---

## Stock Adjustments Create Integration

### Product Selection Field Enhancement

**Visual Design:**
- Add small barcode scan icon button next to product selection field
- Icon: Mini barcode icon, 20x20px
- Position: Right side of product input, before clear/dropdown buttons
- Style: Subtle gray (`text-gray-400`), becomes primary blue on hover
- Tooltip: "Scan barcode to find product"
- Touch target: Minimum 44x44px on mobile

**Behavior:**
1. User clicks scan icon next to product field
2. `BarcodeScannerModal` opens (same modal as Products)
3. User scans barcode or enters manually
4. On successful scan:
   - Modal closes automatically
   - Product is auto-selected in the product field
   - Triggers same logic as manual product selection
   - Form validation runs (if any)
   - Focus moves to next field (adjustment type or quantity)
5. On error (product not found):
   - Modal shows error message
   - Product field remains empty
   - User can try again or close and select manually

**Integration with Existing Form:**
- Determine current product selection mechanism (dropdown, autocomplete, combobox)
- Use same v-model or method to set product value
- Trigger any existing watchers or validation
- Preserve other form state (location, date, notes)

**State Management:**
```javascript
const showScannerModal = ref(false);

const openScanner = () => {
  showScannerModal.value = true;
};

const closeScanner = () => {
  showScannerModal.value = false;
};

const handleProductFound = (product) => {
  // Set product in form (exact mechanism depends on current implementation)
  form.product_id = product.id;
  // Or: selectedProduct.value = product;
  // Or: form.product = product;

  closeScanner();

  // Focus next field
  nextTick(() => {
    // Focus adjustment type or quantity field
  });
};
```

---

## Error Handling & Edge Cases

### Error Scenarios

**1. Camera Permission Denied**
- BarcodeScannerModal already handles via `@error` event
- Shows error message: "Camera access denied"
- User can switch to manual entry mode
- No additional handling needed

**2. Product Not Found (404)**
- Modal shows: "No product found with this barcode or SKU"
- User options: Try again, switch to manual entry, or close modal
- Products Index: User can close and search/browse manually
- Stock Adjustments: User can close and use product dropdown

**3. Multiple Products with Same Barcode**
- Current API returns single product (first match)
- Acceptable for MVP - barcodes should be unique
- Future enhancement: Show disambiguation list if needed

**4. Network Errors (500, timeout)**
- API call fails: Show "Failed to lookup barcode"
- BarcodeScannerModal already has loading states
- User can retry or close modal

**5. Scanner Already Open**
- Prevent opening multiple modals
- Check `showScannerModal` state before opening
- Keyboard shortcut should toggle (open/close) instead of stacking

**6. Unauthenticated (401)**
- API returns 401 if token expired
- Modal shows: "Authentication required"
- Rare edge case - page would also fail to load

### Loading States

- Modal shows spinner during API lookup (already implemented in BarcodeScannerModal)
- Products Index: No page-level loading needed
- Stock Adjustments: Form remains interactive during scan

---

## Permissions & Security

### Access Control

**Products Index - Floating Scan Button:**
- Visible only if user has `products.view` permission
- Same permission check as viewing products list
- No additional permission needed
- Button hidden via `v-if` if user lacks permission

**Stock Adjustments Create - Scan Icon:**
- Visible only if user has `stock_adjustments.create` permission
- Already checked by the Create page itself
- Scan functionality inherits page-level permissions
- Icon hidden if user lacks permission

**Permission Checks:**
```javascript
// Products Index
<FloatingButton v-if="can('products.view')" ... />

// Stock Adjustments Create
<ScanIcon v-if="can('stock_adjustments.create')" ... />
```

### API Security

**Barcode Lookup Endpoint** (`/api/v1/barcode/{code}`):
- Already protected by Sanctum authentication
- Returns 401 if unauthenticated (modal handles gracefully)
- Scoped to user's organization (multi-tenant)
- No additional security changes needed

### Data Validation

- Barcode/SKU input sanitized by API
- No SQL injection risk - using Eloquent ORM with parameter binding
- Input length limits already enforced
- XSS protection via Vue's template escaping

### Camera Permissions

- Browser-level camera permission prompt (not server-side)
- Handled by html5-qrcode library
- Fallback to manual entry if denied
- No server-side permission needed

**Security Summary:** No new vulnerabilities introduced. Reusing existing secure components and API endpoints.

---

## Mobile & Responsive Design

### Products Index - Floating Button

**Desktop:**
- Fixed bottom-right corner
- 56x56px with hover effects
- 16px margin from edges

**Mobile:**
- Same bottom-right position
- Larger touch target (64x64px)
- 12px margin from edges (smaller screens)
- Z-index above content, doesn't obstruct list
- Button stays visible while scrolling

### Stock Adjustments - Scan Icon

**Desktop:**
- 20x20px icon, 32x32px clickable area
- Adequate spacing from other form controls

**Mobile:**
- Minimum 44x44px tap target (Apple HIG, Material Design)
- Icon scales or padding increases
- Touch-friendly spacing

### Scanner Modal

**Already Responsive** (BarcodeScannerModal):
- `max-w-md w-full mx-4` - Full width on mobile with margins
- `max-h-[90vh] overflow-y-auto` - Fits mobile screens
- Camera view adapts to screen size via props
- Manual entry keyboard-friendly
- Bottom sheet pattern on mobile (modal slides up)

---

## Testing Strategy

### Manual Testing Checklist

**Products Index:**
- [ ] Click floating button → scanner opens
- [ ] Press Ctrl+B → scanner opens
- [ ] Press Ctrl+B again → scanner closes (toggle)
- [ ] Scan valid barcode → navigate to product Show page
- [ ] Scan invalid barcode → see error message
- [ ] Switch to manual entry → enter SKU → navigate to product
- [ ] Deny camera permission → see error, switch to manual works
- [ ] Test on mobile → button visible and touch-friendly

**Stock Adjustments Create:**
- [ ] Click scan icon → scanner opens
- [ ] Scan valid barcode → product auto-fills in form
- [ ] Scan invalid barcode → see error message, form unchanged
- [ ] Product auto-fills → focus moves to next field
- [ ] Switch to manual entry → enter SKU → product auto-fills
- [ ] Test on mobile → icon touch-friendly, modal works

**Cross-Browser:**
- [ ] Chrome/Edge (best camera support)
- [ ] Firefox (good camera support)
- [ ] Safari desktop (decent camera support)
- [ ] Mobile Chrome Android (camera support)
- [ ] Mobile Safari iOS (camera support)

**Permissions:**
- [ ] User without `products.view` → no scan button on Products Index
- [ ] User without `stock_adjustments.create` → no scan icon on Stock Adjustments
- [ ] Unauthenticated API call → see error message

### E2E Tests (Future Enhancement)

Playwright tests can be added for:
- Modal open/close interactions
- Mock barcode API responses (404, 200, 500)
- Navigation after successful scan
- Form auto-fill after successful scan

**Limitation:** Cannot easily test actual camera scanning in E2E - manual testing required.

---

## Implementation Plan

### Phase 1: Products Index Integration
1. Add floating scan button component
2. Wire up BarcodeScannerModal
3. Implement keyboard shortcut (Ctrl+B)
4. Handle navigation on product found
5. Test manually on desktop and mobile

### Phase 2: Stock Adjustments Integration
1. Add scan icon to product selection field
2. Wire up BarcodeScannerModal
3. Implement product auto-fill logic
4. Handle focus management
5. Test manually on desktop and mobile

### Phase 3: Testing & Polish
1. Cross-browser testing
2. Permission checks
3. Error scenario testing
4. Mobile responsiveness verification
5. Documentation updates (user guide)

---

## Success Criteria

1. ✅ Users can scan barcodes on Products Index and navigate to product details
2. ✅ Users can scan barcodes in Stock Adjustments and auto-fill product selection
3. ✅ Keyboard shortcut (Ctrl+B) works on Products Index
4. ✅ Manual entry fallback works in both contexts
5. ✅ Error messages clear and actionable
6. ✅ Mobile responsive and touch-friendly
7. ✅ No new security vulnerabilities
8. ✅ No backend changes required
9. ✅ Works across major browsers
10. ✅ Respects existing permissions

---

## Future Enhancements (Not in Scope)

- Barcode scanning in Order Create page
- Batch product lookup (scan multiple barcodes)
- Scanner keyboard shortcut in Stock Adjustments (if needed)
- Disambiguation UI for duplicate barcodes
- Scan history / recent scans
- Custom keyboard shortcut configuration
- E2E automated tests for scanning workflows

---

## Notes

- Reusing existing components minimizes risk and development time
- No database migrations or backend changes needed
- Camera permissions handled by browser, no server-side changes
- Design maintains consistency with existing PurchaseOrder scanner usage
- Progressive enhancement: Works without camera via manual entry
