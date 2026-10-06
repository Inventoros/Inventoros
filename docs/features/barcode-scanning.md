# Barcode Scanning Feature

## Overview

The barcode scanning feature enables quick product lookup and selection using device cameras or manual barcode entry across multiple workflows.

## Where It's Available

### 1. Products Index Page
- **Access:** Floating blue button (bottom-right corner)
- **Keyboard Shortcut:** `Ctrl+B` (Windows/Linux) or `Cmd+B` (Mac)
- **Behavior:** Scan → Navigate to product Show page
- **Permission Required:** `view_products`

### 2. Stock Adjustments Create Page
- **Access:** Scan icon next to product dropdown field
- **Behavior:** Scan → Auto-fill product selection → Focus quantity field
- **Permissions Required:** `manage_stock` and `view_products` for barcode lookup

### 3. Purchase Orders (Already Implemented)
- **Access:** Scan icon in receive workflow
- **Behavior:** Scan → Auto-fill received items

### 4. Order Create Page
- **Access:** Scan button in the order items section
- **Behavior:** Scan a product or variant barcode to add the matching order line
- **Permissions Required:** `create_orders` and `view_products` for barcode lookup

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
- Check `view_products` for barcode lookup and the permission for the page (`manage_stock` or `create_orders`)
- Verify JavaScript is enabled
- Try refreshing the page

## Technical Details

- **Component:** `resources/js/Components/BarcodeScannerModal.vue`
- **Library:** html5-qrcode
- **Browser Endpoint:** `GET /barcode/lookup?code=...`, using the signed-in browser session
- **API Endpoint:** `GET /api/v1/barcode/{code}`, using a Sanctum bearer token
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

- Batch scanning mode
- Custom keyboard shortcuts
- Scan history/recent scans
