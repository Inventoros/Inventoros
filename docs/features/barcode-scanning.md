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
