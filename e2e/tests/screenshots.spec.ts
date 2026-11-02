import { test, expect } from '@playwright/test';
import { fileURLToPath } from 'url';
import path from 'path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const screenshotDir = path.join(__dirname, '../../screenshots');

const pages = [
    { name: 'dashboard', path: '/dashboard', waitFor: 'h1' },
    { name: 'products', path: '/products', waitFor: 'table' },
    { name: 'orders', path: '/orders', waitFor: 'table' },
    { name: 'customers', path: '/customers', waitFor: 'table' },
    { name: 'locations', path: '/locations', waitFor: 'main' },
    { name: 'purchase-orders', path: '/purchase-orders', waitFor: 'table' },
    { name: 'reports', path: '/reports', waitFor: 'main' },
    { name: 'categories', path: '/categories', waitFor: 'main' },
    { name: 'suppliers', path: '/suppliers', waitFor: 'table' },
    { name: 'settings', path: '/settings/organization', waitFor: 'form' },
];

const themes = [
    { name: 'dark', suffix: '' },
    { name: 'light', suffix: '-light' },
] as const;

for (const theme of themes) {
    for (const pg of pages) {
        test(`screenshot ${theme.name}: ${pg.name}`, async ({ page }) => {
            await page.emulateMedia({ colorScheme: theme.name });
            // Set the preference before the app mounts so the theme toggle,
            // charts and page all initialize in the theme being captured.
            await page.addInitScript((name) => localStorage.setItem('theme', name), theme.name);
            await page.goto(pg.path, { waitUntil: 'networkidle' });
            await expect(page.locator('h1')).toBeVisible();
            await page.locator(pg.waitFor).first().waitFor({ state: 'visible' });
            if (theme.name === 'dark') await expect(page.locator('html')).toHaveClass(/dark/);
            else await expect(page.locator('html')).not.toHaveClass(/dark/);
            await page.evaluate(() => document.fonts.ready);
            // Let animations settle
            await page.waitForTimeout(500);
            await page.screenshot({
                path: path.join(screenshotDir, `${pg.name}${theme.suffix}.png`),
                fullPage: false,
                animations: 'disabled',
                caret: 'hide',
            });
            if (pg.name === 'products') {
                await page.getByRole('button', { name: 'Full table view', exact: true }).click();
                const fullTable = page.locator('.table-full-dialog[open]');
                await expect(fullTable.locator('table')).toBeVisible();
                await expect(fullTable.locator('.table-pagination')).toBeVisible();
                await page.screenshot({
                    path: path.join(screenshotDir, `products-full${theme.suffix}.png`),
                    fullPage: false,
                    animations: 'disabled',
                    caret: 'hide',
                });
            }
        });
    }
}
