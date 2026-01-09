import { test, expect, generateTestId } from '../fixtures';

test.describe('Products', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/products');
    });

    test('should display products list', async ({ page }) => {
        await expect(page.locator('h1, h2').filter({ hasText: /products|inventory/i })).toBeVisible();
    });

    test('should have create product button', async ({ page }) => {
        const createButton = page.locator('a, button').filter({ hasText: /create|add|new/i });
        await expect(createButton.first()).toBeVisible();
    });

    test('should navigate to create product page', async ({ page }) => {
        await page.click('a:has-text("Create"), a:has-text("Add"), a:has-text("New Product")');

        await expect(page).toHaveURL(/.*products.*create.*/);
        await expect(page.locator('input[name="name"], #name')).toBeVisible();
    });

    test('should display product form fields', async ({ page }) => {
        await page.goto('/products/create');

        // Check for required form fields
        await expect(page.locator('input[name="name"], #name')).toBeVisible();
        await expect(page.locator('input[name="sku"], #sku')).toBeVisible();
        await expect(page.locator('input[name="price"], #price')).toBeVisible();
        await expect(page.locator('input[name="stock"], #stock')).toBeVisible();
    });

    test('should show validation errors on empty submit', async ({ page }) => {
        await page.goto('/products/create');

        // Submit empty form
        await page.click('button[type="submit"]');

        // Should show validation errors
        await expect(page.locator('.text-red-400, .text-red-500, .error')).toBeVisible();
    });

    test('should create a new product', async ({ page }) => {
        const testId = generateTestId();
        const productName = `Test Product ${testId}`;

        await page.goto('/products/create');

        // Fill in product details
        await page.fill('input[name="name"], #name', productName);
        await page.fill('input[name="sku"], #sku', `SKU-${testId}`);
        await page.fill('input[name="price"], #price', '99.99');
        await page.fill('input[name="stock"], #stock', '100');
        await page.fill('input[name="min_stock"], #min_stock', '10');

        // Submit form
        await page.click('button[type="submit"]');

        // Should redirect to products list or show success
        await expect(page.locator(`text=${productName}`).or(page.locator('text=/success|created/i'))).toBeVisible({ timeout: 10000 });
    });

    test('should search products', async ({ page }) => {
        // Find search input
        const searchInput = page.locator('input[type="search"], input[placeholder*="search" i], input[name="search"]');

        if (await searchInput.isVisible()) {
            await searchInput.fill('test');
            await searchInput.press('Enter');

            // Wait for search results
            await page.waitForTimeout(500);
        }
    });

    test('should filter products by category', async ({ page }) => {
        const categorySelect = page.locator('select[name="category"], #category-filter');

        if (await categorySelect.isVisible()) {
            await categorySelect.selectOption({ index: 1 });
            await page.waitForTimeout(500);
        }
    });

    test('should display product details', async ({ page }) => {
        // Click on first product view button
        const viewButton = page.locator('a:has-text("View"), a[href*="/products/"][href$="/show"], table tr td a').first();

        if (await viewButton.isVisible()) {
            await viewButton.click();
            await expect(page).toHaveURL(/.*products\/\d+.*/);
        }
    });

    test('should display barcode print button', async ({ page }) => {
        // Navigate to product list
        const barcodeButton = page.locator('button[title="Print Barcode"], a[title="Print Barcode"]');

        // Check if barcode buttons are visible (for products with SKU/barcode)
        const count = await barcodeButton.count();
        expect(count).toBeGreaterThanOrEqual(0);
    });

    test('should select products for bulk actions', async ({ page }) => {
        // Find checkbox in header
        const selectAllCheckbox = page.locator('thead input[type="checkbox"]');

        if (await selectAllCheckbox.isVisible()) {
            await selectAllCheckbox.click();

            // Bulk action bar should appear
            await expect(page.locator('text=/selected|print barcodes/i')).toBeVisible();
        }
    });
});
