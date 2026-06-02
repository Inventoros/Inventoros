import { test, expect } from '../fixtures';

test.describe('Dashboard', () => {
    test('should display dashboard after login', async ({ page }) => {
        await page.goto('/dashboard');

        await expect(page.locator('h1, h2').filter({ hasText: /dashboard/i })).toBeVisible();
    });

    test('should display key metrics', async ({ page }) => {
        await page.goto('/dashboard');

        // Check for common dashboard metrics
        await expect(page.locator('text=/total products|products/i').first()).toBeVisible();
        await expect(page.locator('text=/orders|sales/i').first()).toBeVisible();
    });

    test('should have navigation sidebar', async ({ page }) => {
        await page.goto('/dashboard');

        // Check sidebar navigation links (exact to avoid "Purchase Orders" / "Work Orders" clashes)
        const nav = page.locator('nav');
        await expect(nav.getByRole('link', { name: 'Dashboard', exact: true })).toBeVisible();
        await expect(nav.getByRole('link', { name: 'Inventory', exact: true })).toBeVisible();
        await expect(nav.getByRole('link', { name: 'Orders', exact: true })).toBeVisible();
    });

    test('should navigate to products from sidebar', async ({ page }) => {
        await page.goto('/dashboard');

        await page.click('nav >> text=Inventory');

        await expect(page).toHaveURL(/.*products.*/);
    });

    test('should navigate to orders from sidebar', async ({ page }) => {
        await page.goto('/dashboard');

        await page.click('nav >> text=Orders');

        await expect(page).toHaveURL(/.*orders.*/);
    });

    test('should display user menu', async ({ page }) => {
        await page.goto('/dashboard');

        // Look for user dropdown or profile link
        const userMenu = page.locator('[data-testid="user-menu"], button:has-text("Profile"), .user-dropdown');
        await expect(userMenu.first()).toBeVisible();
    });
});
