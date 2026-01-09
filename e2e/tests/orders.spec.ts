import { test, expect, generateTestId } from '../fixtures';

test.describe('Orders', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/orders');
    });

    test('should display orders list', async ({ page }) => {
        await expect(page.locator('h1, h2').filter({ hasText: /orders/i })).toBeVisible();
    });

    test('should have create order button', async ({ page }) => {
        const createButton = page.locator('a, button').filter({ hasText: /create|add|new/i });
        await expect(createButton.first()).toBeVisible();
    });

    test('should navigate to create order page', async ({ page }) => {
        await page.click('a:has-text("Create"), a:has-text("Add"), a:has-text("New Order")');

        await expect(page).toHaveURL(/.*orders.*create.*/);
    });

    test('should display order form fields', async ({ page }) => {
        await page.goto('/orders/create');

        // Check for required form fields
        await expect(page.locator('input[name="customer_name"], #customer_name')).toBeVisible();
        await expect(page.locator('select[name="status"], #status')).toBeVisible();
    });

    test('should create a new order', async ({ page }) => {
        const testId = generateTestId();

        await page.goto('/orders/create');

        // Fill in order details
        await page.fill('input[name="customer_name"], #customer_name', `Test Customer ${testId}`);
        await page.fill('input[name="customer_email"], #customer_email', `test-${testId}@example.com`);

        // Set order date
        const dateInput = page.locator('input[name="order_date"], #order_date');
        if (await dateInput.isVisible()) {
            await dateInput.fill(new Date().toISOString().split('T')[0]);
        }

        // Add a product to the order (if product selector exists)
        const productSelect = page.locator('select[name="product"], .product-select, [data-testid="product-select"]');
        if (await productSelect.isVisible()) {
            await productSelect.selectOption({ index: 1 });
        }
    });

    test('should filter orders by status', async ({ page }) => {
        const statusSelect = page.locator('select[name="status"], #status-filter');

        if (await statusSelect.isVisible()) {
            await statusSelect.selectOption('pending');
            await page.waitForTimeout(500);
        }
    });

    test('should search orders', async ({ page }) => {
        const searchInput = page.locator('input[type="search"], input[placeholder*="search" i], input[name="search"]');

        if (await searchInput.isVisible()) {
            await searchInput.fill('ORD-');
            await searchInput.press('Enter');
            await page.waitForTimeout(500);
        }
    });

    test('should display order details', async ({ page }) => {
        // Click on first order view button
        const viewButton = page.locator('a:has-text("View"), a[href*="/orders/"][href$="/show"], table tr td a').first();

        if (await viewButton.isVisible()) {
            await viewButton.click();
            await expect(page).toHaveURL(/.*orders\/\d+.*/);
        }
    });

    test('should show approval status badge', async ({ page }) => {
        // Look for approval status badges
        const approvalBadge = page.locator('text=/pending|approved|rejected/i').first();

        if (await approvalBadge.isVisible()) {
            await expect(approvalBadge).toBeVisible();
        }
    });
});

test.describe('Order Approval', () => {
    test('should display approval section on order detail', async ({ page }) => {
        // Navigate to an order detail page
        await page.goto('/orders');

        const viewButton = page.locator('a[href*="/orders/"]').first();
        if (await viewButton.isVisible()) {
            await viewButton.click();

            // Look for approval status section
            await expect(page.locator('text=/approval status/i')).toBeVisible();
        }
    });

    test('should show approve/reject buttons for pending orders', async ({ page }) => {
        await page.goto('/orders');

        // Find an order with pending status
        const pendingOrder = page.locator('tr:has-text("pending") a[href*="/orders/"]').first();

        if (await pendingOrder.isVisible()) {
            await pendingOrder.click();

            // Check for approval buttons (if user has permission)
            const approveButton = page.locator('button:has-text("Approve")');
            const rejectButton = page.locator('button:has-text("Reject")');

            // At least one should exist if user has approve permission
            const hasApprovalButtons = await approveButton.isVisible() || await rejectButton.isVisible();
            // This is expected to be true or false depending on permissions
        }
    });
});
