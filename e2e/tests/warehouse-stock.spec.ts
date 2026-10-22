import { test, expect } from '@playwright/test';

test.beforeEach(async ({ context, baseURL }) => {
    const origin = new URL(baseURL!).origin;
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
});

test('warehouse balances sort with column-named controls and preserve filters', async ({ page }) => {
    await page.goto('/stock?include_zero=1');
    await expect(page.getByRole('heading', { name: 'Stock by warehouse', level: 1 })).toBeVisible();
    const table = page.getByRole('table');
    const response = page.waitForResponse(response => new URL(response.url()).pathname === '/stock' && response.url().includes('sort_by=available'));
    await table.getByRole('button', { name: 'Available', exact: true }).click();
    expect((await response).status()).toBe(200);
    await expect(page).toHaveURL(/sort_by=available/);
    await expect(table.getByRole('columnheader').filter({ has: page.getByRole('button', { name: 'Available', exact: true }) })).toHaveAttribute('aria-sort', 'ascending');
    await table.getByRole('button', { name: 'Available', exact: true }).click();
    await expect(page).toHaveURL(/sort_dir=desc/);
    await expect(page.getByRole('checkbox', { name: 'Include zero balances' })).toBeChecked();
});

test('warehouse stock CSV exports the selected search', async ({ page }) => {
    await page.goto('/stock?search=SKU-10001&include_zero=1');
    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Export CSV', exact: true }).click();
    expect((await download).suggestedFilename()).toMatch(/^warehouse-stock-.*\.csv$/);
});
