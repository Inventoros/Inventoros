import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const pages = ['products', 'orders', 'purchase-orders', 'customers', 'suppliers', 'returns', 'stock-transfers', 'stock-audits', 'work-orders', 'warehouses'];
const query = 'UX-NO-MATCH-4591';

for (const width of [320, 1440]) {
    for (const path of pages) {
        test(`${path}: empty search fits and recovers at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 812 });
            await page.goto(`/${path}?search=${query}`, { waitUntil: 'networkidle' });
            const empty = page.locator('.empty-state');
            await expect(empty.getByRole('heading', { name: 'No results found', exact: true })).toBeVisible();
            const clear = empty.getByRole('button', { name: 'Clear Filters', exact: true });
            await expect(clear).toBeInViewport();
            await expect(empty.getByRole('link')).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Full table view', exact: true })).toHaveCount(0);
            expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
            const box = await clear.boundingBox();
            expect(box!.x).toBeGreaterThanOrEqual(0);
            expect(box!.x + box!.width).toBeLessThanOrEqual(width);
            expect(box!.height).toBeGreaterThanOrEqual(44);
            await clear.click();
            await expect(page.locator('.empty-state').getByRole('button', { name: 'Clear Filters', exact: true })).toHaveCount(0);
            await expect(page.locator('.table-quick-search input')).toHaveValue('');
            expect(new URL(page.url()).searchParams.get('search')).not.toBe(query);
        });
    }
}

test('clearing Inventory also removes low-stock, category, and location filters', async ({ page }) => {
    await page.goto(`/products?search=${query}&low_stock=1&category=999999&location=999999`, { waitUntil: 'networkidle' });
    await page.locator('.empty-state').getByRole('button', { name: 'Clear Filters', exact: true }).click();
    await expect(page.locator('.table-viewport tbody tr').first()).toBeVisible();
    for (const key of ['low_stock', 'category', 'location', 'search']) expect(new URL(page.url()).searchParams.get(key)).toBeNull();
    // The shared table can still expand after its empty state is replaced.
    const expand = page.getByRole('button', { name: 'Full table view', exact: true });
    await expand.click();
    await expect(page.locator('dialog[open]')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('dialog[open]')).toHaveCount(0);
    await expect(expand).toBeFocused();
});

async function emptyFixture(page: Page, path: string, collection: string, permissions: string[], locale = 'en') {
    await page.route(`**/${path}`, async route => {
        const response = await route.fetch();
        const body = await response.text();
        const match = body.match(/(<script\b[^>]*\bdata-page="app"[^>]*>)([\s\S]*?)(<\/script>)/);
        if (!match) throw new Error('Expected an Inertia document.');
        const data = JSON.parse(match[2]);
        data.props[collection] = { ...data.props[collection], data: [], total: 0 };
        data.props.filters = {};
        data.props.auth.permissions = permissions;
        data.props.locale = locale;
        const json = JSON.stringify(data).replaceAll('<', '\\u003c');
        await route.fulfill({ response, body: body.replace(match[0], `${match[1]}${json}${match[3]}`) });
    });
    await page.goto(`/${path}`, { waitUntil: 'networkidle' });
}

for (const [path, collection, permission] of [
    ['products', 'products', 'view_products'], ['purchase-orders', 'purchaseOrders', 'view_purchase_orders'],
    ['customers', 'customers', 'view_customers'], ['suppliers', 'suppliers', 'view_suppliers'],
    ['warehouses', 'warehouses', 'view_warehouses'],
]) {
    test(`${path}: first-use state respects read-only permissions`, async ({ page }) => {
        await emptyFixture(page, path, collection, [permission]);
        await expect(page.locator('.empty-state')).toBeVisible();
        await expect(page.locator('.empty-state').getByRole('link')).toHaveCount(0);
        await expect(page.locator('.empty-state').getByRole('button')).toHaveCount(0);
        await expect(page.locator('.empty-state')).not.toContainText('Get started');
    });
}

test('first-use action remains reachable in Arabic, dark mode, high contrast, and a short viewport', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 320 });
    await page.addInitScript(() => localStorage.setItem('theme', 'dark'));
    await emptyFixture(page, 'stock-transfers', 'transfers', ['transfer_stock'], 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    const action = page.locator('.empty-state a');
    await action.scrollIntoViewIfNeeded();
    await expect(action).toBeInViewport();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
    expect((await new AxeBuilder({ page }).include('.table-empty-content').analyze()).violations).toEqual([]);
    await page.emulateMedia({ forcedColors: 'active' });
    await action.focus();
    await expect(action).toBeFocused();
    expect((await action.boundingBox())!.height).toBeGreaterThanOrEqual(44);
});

test('empty search recovery is keyboard accessible and has no scoped axe violations', async ({ page }) => {
    await page.goto(`/orders?search=${query}`, { waitUntil: 'networkidle' });
    const clear = page.locator('.empty-state button');
    await clear.focus();
    await expect(clear).toBeFocused();
    expect((await new AxeBuilder({ page }).include('.table-empty-content').analyze()).violations).toEqual([]);
    await page.keyboard.press('Enter');
    await expect(page.locator('.table-viewport tbody tr').first()).toBeVisible();
});
