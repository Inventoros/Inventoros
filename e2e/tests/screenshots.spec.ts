import { test } from '@playwright/test';
import { fileURLToPath } from 'url';
import path from 'path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const screenshotDir = path.join(__dirname, '../../screenshots');

const pages = [
    { name: 'dashboard', path: '/dashboard', waitFor: 'h2' },
    { name: 'products', path: '/products', waitFor: 'table' },
    { name: 'orders', path: '/orders', waitFor: 'table' },
    { name: 'customers', path: '/customers', waitFor: 'table' },
    { name: 'purchase-orders', path: '/purchase-orders', waitFor: 'table' },
    { name: 'reports', path: '/reports', waitFor: 'main' },
    { name: 'categories', path: '/categories', waitFor: 'table' },
    { name: 'suppliers', path: '/suppliers', waitFor: 'table' },
    { name: 'settings', path: '/settings/organization', waitFor: 'form' },
];

for (const pg of pages) {
    test(`screenshot: ${pg.name}`, async ({ page }) => {
        await page.goto(pg.path, { waitUntil: 'networkidle' });
        await page.locator(pg.waitFor).first().waitFor({ timeout: 10000 }).catch(() => {});
        // Let animations settle
        await page.waitForTimeout(500);
        await page.screenshot({
            path: path.join(screenshotDir, `${pg.name}.png`),
            fullPage: false,
        });
    });
}
