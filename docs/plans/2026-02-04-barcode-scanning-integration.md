# Barcode Scanning Integration Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Integrate barcode scanning into Products Index and Stock Adjustments Create workflows for quick product lookup and selection.

**Architecture:** Reuse existing BarcodeScannerModal component in two new contexts: (1) floating scan button on Products Index that navigates to product details, (2) scan icon in Stock Adjustments product field that auto-fills selection. No backend changes needed - uses existing `/api/v1/barcode/{code}` endpoint.

**Tech Stack:** Vue 3 Composition API, Inertia.js, html5-qrcode library, existing BarcodeScannerModal component

---

## Task 1: Add Floating Scan Button to Products Index

**Files:**
- Modify: `resources/js/Pages/Products/Index.vue:1-400`
- Test: Manual testing (scan button appears, opens modal, navigates on scan)

**Step 1: Import BarcodeScannerModal and add state**

Add to `<script setup>` section after line 5:

```javascript
import BarcodeScannerModal from '@/Components/BarcodeScannerModal.vue';
```

Add after line 21:

```javascript
// Barcode scanner state
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

**Step 2: Add keyboard shortcut handler**

Add after the `handleProductFound` function:

```javascript
// Keyboard shortcut: Ctrl+B to open scanner
const handleKeydown = (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
        e.preventDefault();
        showScannerModal.value = !showScannerModal.value;
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
});
```

Add imports at top of script:

```javascript
import { ref, computed, onMounted, onUnmounted } from 'vue';
```

**Step 3: Add floating scan button to template**

Add before the closing `</AuthenticatedLayout>` tag (search for closing tag near end of file):

```vue
        <!-- Floating Barcode Scan Button -->
        <button
            v-if="$page.props.auth.permissions.includes('products.view')"
            @click="openScanner"
            class="fixed bottom-6 right-6 w-14 h-14 bg-primary-400 hover:bg-primary-500 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-200 hover:scale-110 flex items-center justify-center z-40"
            title="Scan Barcode (Ctrl+B)"
        >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
            </svg>
        </button>

        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal
            :show="showScannerModal"
            @close="closeScanner"
            @product-found="handleProductFound"
        />
    </AuthenticatedLayout>
```

**Step 4: Test manually**

Test checklist:
1. Navigate to Products Index page
2. Verify floating button appears in bottom-right corner
3. Click button → modal opens
4. Press Ctrl+B → modal toggles (open/close)
5. Scan valid barcode → navigates to product Show page
6. Scan invalid barcode → shows error, stays on page
7. Test manual entry mode
8. Test on mobile (button touch-friendly)

**Step 5: Commit**

```bash
cd .worktrees/barcode-scanning-integration
git add resources/js/Pages/Products/Index.vue
git commit -m "Add floating barcode scan button to Products Index

- Import BarcodeScannerModal component
- Add scan button in bottom-right corner (FAB pattern)
- Implement Ctrl+B keyboard shortcut to toggle scanner
- Navigate to product Show page on successful scan
- Respect products.view permission"
```

---

## Task 2: Add Scan Icon to Stock Adjustments Product Field

**Files:**
- Modify: `resources/js/Pages/StockAdjustments/Create.vue:1-200`
- Test: Manual testing (scan icon appears, opens modal, auto-fills product)

**Step 1: Import BarcodeScannerModal and add state**

Add to `<script setup>` section after line 3:

```javascript
import BarcodeScannerModal from '@/Components/BarcodeScannerModal.vue';
import { computed, watch, ref, nextTick } from 'vue';
```

Add after line 16:

```javascript
// Barcode scanner state
const showScannerModal = ref(false);

const openScanner = () => {
    showScannerModal.value = true;
};

const closeScanner = () => {
    showScannerModal.value = false;
};

const handleProductFound = (product) => {
    form.product_id = product.id;
    closeScanner();

    // Focus next field (adjustment quantity)
    nextTick(() => {
        const qtyInput = document.querySelector('input[type="number"]');
        if (qtyInput) qtyInput.focus();
    });
};
```

**Step 2: Add scan icon button to product selection field**

Find the product selection `<div>` (around line 75-94) and replace it with:

```vue
                        <!-- Product Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Product <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <select
                                    v-model="form.product_id"
                                    required
                                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 pr-12"
                                    :class="{ 'border-red-500': form.errors.product_id }"
                                >
                                    <option value="">Select a product</option>
                                    <option v-for="product in products" :key="product.id" :value="product.id">
                                        {{ product.name }} ({{ product.sku }}) - Current Stock: {{ product.stock }}
                                    </option>
                                </select>
                                <!-- Scan Icon Button -->
                                <button
                                    v-if="$page.props.auth.permissions.includes('stock_adjustments.create')"
                                    type="button"
                                    @click="openScanner"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 p-2 text-gray-400 hover:text-primary-400 transition-colors"
                                    title="Scan barcode to find product"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                                    </svg>
                                </button>
                            </div>
                            <p v-if="form.errors.product_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.product_id }}
                            </p>
                        </div>
```

**Step 3: Add BarcodeScannerModal to template**

Add before the closing `</AuthenticatedLayout>` tag (near end of file):

```vue
        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal
            :show="showScannerModal"
            @close="closeScanner"
            @product-found="handleProductFound"
        />
    </AuthenticatedLayout>
```

**Step 4: Test manually**

Test checklist:
1. Navigate to Stock Adjustments Create page
2. Verify scan icon appears next to product dropdown
3. Click scan icon → modal opens
4. Scan valid barcode → product auto-fills, focus moves to quantity
5. Scan invalid barcode → shows error, product remains empty
6. Test manual entry mode
7. Verify existing form validation still works
8. Test on mobile (icon touch-friendly)

**Step 5: Commit**

```bash
cd .worktrees/barcode-scanning-integration
git add resources/js/Pages/StockAdjustments/Create.vue
git commit -m "Add barcode scan icon to Stock Adjustments product field

- Import BarcodeScannerModal component
- Add scan icon button next to product dropdown
- Auto-fill product selection on successful scan
- Auto-focus quantity field after product selected
- Respect stock_adjustments.create permission"
```

---

## Task 3: Update Documentation

**Files:**
- Modify: `todo.txt:112-119`
- Create: `docs/features/barcode-scanning.md`

**Step 1: Update todo.txt**

Change lines 112-119 from:

```
  1. Barcode Scanning Integration - PARTIAL
     Status: Scanner component exists but not integrated in workflows
     Location: /resources/js/Components/BarcodeScanner.vue
     Missing:
       - Integration in Products pages
       - Integration in Stock Adjustments
       - Quick product lookup by scan
     Current: Only integrated in Purchase Order pages
```

To:

```
  1. Barcode Scanning Integration - COMPLETE ✓
     Status: Fully integrated in Products Index and Stock Adjustments
     Location: /resources/js/Components/BarcodeScanner.vue
     Features:
       - Products Index: Floating scan button (Ctrl+B) → navigates to product
       - Stock Adjustments: Scan icon next to product field → auto-fills selection
       - Purchase Orders: Already integrated
     Uses: Existing /api/v1/barcode/{code} API endpoint
```

**Step 2: Create feature documentation**

Create `docs/features/barcode-scanning.md`:

```markdown
# Barcode Scanning Feature

## Overview

The barcode scanning feature enables quick product lookup and selection using device cameras or manual barcode entry across multiple workflows.

## Where It's Available

### 1. Products Index Page
- **Access:** Floating blue button (bottom-right corner)
- **Keyboard Shortcut:** `Ctrl+B` (Windows/Linux) or `Cmd+B` (Mac)
- **Behavior:** Scan → Navigate to product Show page
- **Permission Required:** `products.view`

### 2. Stock Adjustments Create Page
- **Access:** Scan icon next to product dropdown field
- **Behavior:** Scan → Auto-fill product selection → Focus quantity field
- **Permission Required:** `stock_adjustments.create`

### 3. Purchase Orders (Already Implemented)
- **Access:** Scan icon in receive workflow
- **Behavior:** Scan → Auto-fill received items

## How to Use

### Camera Mode (Default)
1. Click scan button/icon
2. Grant camera permission (browser prompt, first time only)
3. Point camera at barcode
4. Product auto-detected and selected/navigated

### Manual Entry Mode
1. Click scan button/icon
2. Click "Manual Entry" toggle
3. Type barcode or SKU
4. Click "Lookup" button

## Supported Barcode Formats

- UPC (Universal Product Code)
- EAN (European Article Number)
- Code 128
- QR Codes
- Or match by SKU (exact match)

## Troubleshooting

### Camera Not Working
- Grant camera permission in browser settings
- Use manual entry mode as fallback
- Check browser compatibility (Chrome/Edge recommended)

### Product Not Found
- Verify barcode is assigned to product
- Try entering SKU manually
- Check product exists and is not deleted

### Scanner Won't Open
- Check user permissions (products.view or stock_adjustments.create)
- Verify JavaScript is enabled
- Try refreshing the page

## Technical Details

- **Component:** `resources/js/Components/BarcodeScannerModal.vue`
- **Library:** html5-qrcode
- **API Endpoint:** `GET /api/v1/barcode/{code}`
- **Authentication:** Sanctum token required
- **Multi-tenant:** Scoped to user's organization

## Browser Compatibility

| Browser | Camera Support | Manual Entry |
|---------|---------------|--------------|
| Chrome  | ✓ Excellent   | ✓            |
| Edge    | ✓ Excellent   | ✓            |
| Firefox | ✓ Good        | ✓            |
| Safari  | ✓ Good        | ✓            |
| Mobile  | ✓ Yes         | ✓            |

## Future Enhancements

- Order Create page integration
- Batch scanning mode
- Custom keyboard shortcuts
- Scan history/recent scans
```

**Step 3: Commit**

```bash
cd .worktrees/barcode-scanning-integration
git add todo.txt docs/features/barcode-scanning.md
git commit -m "Update documentation for barcode scanning integration

- Mark barcode scanning as complete in todo.txt
- Add comprehensive feature documentation
- Include usage instructions and troubleshooting guide"
```

---

## Task 4: Final Testing & Verification

**Files:**
- Test: Manual end-to-end testing across all scenarios

**Step 1: Products Index Testing**

Test matrix:
- [ ] Button visible on Products Index
- [ ] Button hidden if user lacks `products.view` permission
- [ ] Click button → modal opens
- [ ] Press Ctrl+B → modal opens/closes
- [ ] Camera mode: Grant permission → camera starts
- [ ] Camera mode: Scan valid barcode → navigate to Show page
- [ ] Camera mode: Scan invalid barcode → error message
- [ ] Manual mode: Toggle to manual entry
- [ ] Manual mode: Enter valid SKU → navigate to Show page
- [ ] Manual mode: Enter invalid SKU → error message
- [ ] Mobile: Button touch-friendly (64px target)
- [ ] Mobile: Modal responsive

**Step 2: Stock Adjustments Testing**

Test matrix:
- [ ] Scan icon visible next to product dropdown
- [ ] Icon hidden if user lacks `stock_adjustments.create` permission
- [ ] Click icon → modal opens
- [ ] Camera mode: Scan valid barcode → product auto-fills
- [ ] Camera mode: Scan → focus moves to quantity field
- [ ] Manual mode: Enter valid SKU → product auto-fills
- [ ] Error handling: Invalid barcode → error, product empty
- [ ] Form validation: Still works after scanning
- [ ] Mobile: Icon touch-friendly (44px target)

**Step 3: Cross-browser Testing**

Test on:
- [ ] Chrome (Windows)
- [ ] Edge (Windows)
- [ ] Firefox (Windows)
- [ ] Mobile Chrome (Android)
- [ ] Mobile Safari (iOS)

**Step 4: Permission Testing**

Test scenarios:
- [ ] User with `products.view` → sees scan button on Products
- [ ] User without `products.view` → no scan button
- [ ] User with `stock_adjustments.create` → sees scan icon
- [ ] User without `stock_adjustments.create` → no scan icon
- [ ] API authentication: Invalid token → 401 error handled

**Step 5: Document any issues found**

If bugs found, create follow-up tasks in this format:

```markdown
### Bug: [Description]
**Severity:** Critical / High / Medium / Low
**Steps to Reproduce:**
1. Step one
2. Step two
3. Expected vs Actual

**Proposed Fix:** [Solution]
```

**Step 6: Mark testing complete**

Once all tests pass, document results:

```bash
cd .worktrees/barcode-scanning-integration
echo "# Barcode Scanning Integration - Test Results

## Test Date: $(date +%Y-%m-%d)

### Products Index: PASS ✓
- All 12 test cases passed
- Tested on Chrome, Firefox, Mobile Chrome

### Stock Adjustments: PASS ✓
- All 9 test cases passed
- Tested on Chrome, Firefox, Mobile Safari

### Permissions: PASS ✓
- All 4 permission scenarios working correctly

### Overall: READY FOR MERGE ✓
" > docs/plans/2026-02-04-barcode-scanning-test-results.md

git add docs/plans/2026-02-04-barcode-scanning-test-results.md
git commit -m "Add test results for barcode scanning integration"
```

---

## Task 5: Prepare for Merge

**Files:**
- Check: Git status, branch cleanliness

**Step 1: Verify all commits**

```bash
cd .worktrees/barcode-scanning-integration
git log --oneline develop..HEAD
```

Expected commits:
1. Install Laravel Boost dev dependency
2. Add floating barcode scan button to Products Index
3. Add barcode scan icon to Stock Adjustments product field
4. Update documentation for barcode scanning integration
5. Add test results for barcode scanning integration

**Step 2: Check for uncommitted changes**

```bash
cd .worktrees/barcode-scanning-integration
git status
```

Expected: `nothing to commit, working tree clean`

If uncommitted changes exist, review and commit or discard.

**Step 3: Run build to verify no compile errors**

```bash
cd .worktrees/barcode-scanning-integration
npm run build
```

Expected: Build completes successfully without errors

**Step 4: Push feature branch to remote**

```bash
cd .worktrees/barcode-scanning-integration
git push -u origin feature/barcode-scanning-integration
```

**Step 5: Use finishing-a-development-branch skill**

Once pushed, use the `superpowers:finishing-a-development-branch` skill to decide next steps (merge, PR, or cleanup).

---

## Success Criteria

✓ Floating scan button on Products Index
✓ Keyboard shortcut (Ctrl+B) works
✓ Scan → Navigate to product Show page
✓ Scan icon in Stock Adjustments product field
✓ Scan → Auto-fill product selection
✓ Manual entry fallback works in both contexts
✓ Permissions respected (products.view, stock_adjustments.create)
✓ Mobile responsive and touch-friendly
✓ Error messages clear and actionable
✓ Documentation updated (todo.txt + feature docs)
✓ All manual tests pass
✓ No backend changes required
✓ Reuses existing BarcodeScannerModal component

---

## Estimated Time

- Task 1: 15 minutes (Products Index integration)
- Task 2: 15 minutes (Stock Adjustments integration)
- Task 3: 10 minutes (Documentation)
- Task 4: 30 minutes (Testing)
- Task 5: 10 minutes (Merge prep)

**Total: ~80 minutes** (1 hour 20 minutes)

---

## Notes

- No database migrations needed
- No new API endpoints needed
- No new components created (reusing existing)
- No breaking changes
- Can be deployed immediately after merge
- Design document: `docs/plans/2026-02-04-barcode-scanning-integration-design.md`
