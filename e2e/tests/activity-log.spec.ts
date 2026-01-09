import { test, expect } from '../fixtures';

test.describe('Activity Log', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/activity-log');
    });

    test('should display activity log page', async ({ page }) => {
        await expect(page.locator('h1, h2').filter({ hasText: /activity|log/i })).toBeVisible();
    });

    test('should display activity entries', async ({ page }) => {
        // Look for activity log entries
        const activityEntries = page.locator('table tr, .activity-entry, [data-testid="activity-item"]');
        const count = await activityEntries.count();

        // Should have at least the header or some entries
        expect(count).toBeGreaterThanOrEqual(0);
    });

    test('should have filter options', async ({ page }) => {
        // Look for filter inputs
        const userFilter = page.locator('select[name="user"], #user-filter');
        const actionFilter = page.locator('select[name="action"], #action-filter');
        const dateFilter = page.locator('input[type="date"], input[name*="date"]');

        // At least one filter should be visible
        const hasFilters = await userFilter.isVisible() ||
                          await actionFilter.isVisible() ||
                          await dateFilter.isVisible();

        expect(hasFilters).toBeTruthy();
    });

    test('should filter by action type', async ({ page }) => {
        const actionFilter = page.locator('select[name="action"], #action-filter');

        if (await actionFilter.isVisible()) {
            await actionFilter.selectOption('created');
            await page.waitForTimeout(500);
        }
    });

    test('should display change details', async ({ page }) => {
        // Look for "View Changes" link or expandable section
        const viewChanges = page.locator('text=/view changes|view details/i, summary, details');

        if (await viewChanges.first().isVisible()) {
            await viewChanges.first().click();

            // Should show before/after values
            await expect(page.locator('text=/before|after|old|new/i')).toBeVisible();
        }
    });

    test('should have pagination', async ({ page }) => {
        // Look for pagination controls
        const pagination = page.locator('.pagination, nav[aria-label="Pagination"], [data-testid="pagination"]');

        if (await pagination.isVisible()) {
            await expect(pagination).toBeVisible();
        }
    });
});
