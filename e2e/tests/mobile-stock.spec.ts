import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const balances = [
    { product_id: 990001, product_variant_id: 990011, sku: 'SHELF-BLUE-L', product_name: 'Industrial shelving unit with adjustable brackets', variant_title: 'Blue / Large', warehouse_id: 1, warehouse_name: 'Toronto distribution centre', location_id: null, location_name: null, on_hand: 18, reserved: 6, available: 12, in_transit: 4 },
    { product_id: 990001, product_variant_id: 990011, sku: 'SHELF-BLUE-L', product_name: 'Industrial shelving unit with adjustable brackets', variant_title: 'Blue / Large', warehouse_id: 2, warehouse_name: 'Montreal depot', location_id: null, location_name: null, on_hand: 3, reserved: 3, available: 0, in_transit: 9 },
    { product_id: 990002, product_variant_id: null, sku: 'BOX-2002', product_name: 'Reinforced corrugated shipping box', variant_title: null, warehouse_id: 1, warehouse_name: 'Toronto distribution centre', location_id: 11, location_name: 'Aisle 3 / Bin 12', on_hand: 1000000000, reserved: 123, available: 999999877, in_transit: 0 },
];

async function render(page: Page, locale = 'en', overrides: Record<string, unknown> = {}) {
    await page.route('**/stock*', async route => {
        const response = await route.fetch();
        const body = await response.text();
        if (!body.includes('data-page="app"')) return route.fulfill({ response, body });
        const match = body.match(/(<script\b[^>]*\bdata-page="app"[^>]*>)([\s\S]*?)(<\/script>)/)!;
        const data = JSON.parse(match[2]);
        data.props = { ...data.props, locale, filters: {}, ...overrides };
        data.props.balances = { ...data.props.balances, data: balances, total: 3, ...((overrides.balances as object) || {}) };
        const json = JSON.stringify(data).replaceAll('<', '\\u003c');
        await route.fulfill({ response, body: body.replace(match[0], `${match[1]}${json}${match[3]}`) });
    }, { times: 1 });
    await page.goto('/stock', { waitUntil: 'networkidle' });
}

for (const width of [320, 375, 768, 1024, 1440, 1920, 3440]) {
    test(`stock records preserve quantities and fit at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await render(page);
        const rows = page.locator('.stock-record');
        await expect(rows).toHaveCount(3);
        await expect(page.getByRole('table')).toHaveCount(1);
        await expect(page.getByRole('columnheader')).toHaveCount(9);
        for (const [index, row] of (await rows.all()).entries()) {
            await expect(row.getByRole('cell')).toHaveCount(9);
            await expect(row.locator('.stock-quantity-value')).toHaveText(['on_hand', 'reserved', 'available', 'in_transit'].map(key => String((balances[index] as any)[key])));
        }
        await expect(rows.nth(0)).toContainText('Toronto distribution centre');
        await expect(rows.nth(1)).toContainText('Montreal depot');
        await expect(rows.nth(0)).toContainText('Blue / Large');
        expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
        if (width <= 1024) {
            const first = rows.first();
            await expect(first.locator('.stock-available')).toBeInViewport();
            for (const value of await first.locator('.stock-quantity-value').all()) {
                const box = await value.boundingBox();
                expect(box!.x).toBeGreaterThanOrEqual(0);
                expect(box!.x + box!.width).toBeLessThanOrEqual(width);
            }
            expect(await first.evaluate(element => getComputedStyle(element).display)).toBe('grid');
            const available = await first.locator('.stock-available').boundingBox();
            const onHand = await first.locator('.stock-on_hand').boundingBox();
            expect(available!.y).toBeLessThan(onHand!.y);
            await expect(page.getByLabel('Sort by', { exact: true })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Available', exact: true })).toHaveCount(0);
        }
    });
}

test('phone sorting keeps applied filters and exposes all sort choices', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/stock?include_zero=1', { waitUntil: 'networkidle' });
    await expect(page.getByLabel('Sort by', { exact: true }).locator('option')).toHaveCount(9);
    await page.getByLabel('Sort by', { exact: true }).selectOption('available');
    await expect(page).toHaveURL(/sort_by=available/);
    await page.getByLabel('Direction', { exact: true }).selectOption('desc');
    await expect(page).toHaveURL(/sort_dir=desc/);
    await page.locator('.table-filters > summary').click();
    await expect(page.getByRole('checkbox', { name: 'Include zero balances' })).toBeChecked();
    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.getByLabel('Sort by', { exact: true })).toHaveValue('available');
    await expect(page.getByLabel('Direction', { exact: true })).toHaveValue('desc');
});

test('empty results recover through the shared empty state', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 812 });
    await page.goto('/stock?search=UX-NO-MATCH-9042&include_zero=1', { waitUntil: 'networkidle' });
    const clear = page.locator('.empty-state').getByRole('button', { name: 'Clear Filters', exact: true });
    await expect(clear).toBeInViewport();
    await clear.click();
    await expect(page.locator('.stock-record').first()).toBeVisible();
    expect(new URL(page.url()).searchParams.get('search')).not.toBe('UX-NO-MATCH-9042');
});

test('location constraints survive sorting and CSV links', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('/stock?include_zero=1', { waitUntil: 'networkidle' });
    const location = await page.locator('script[data-page="app"]').evaluate(element => JSON.parse(element.textContent!).props.balances.data.find((row: any) => row.location_id)?.location_id);
    expect(location).toBeTruthy();
    await page.goto(`/stock?include_zero=1&location_id=${location}`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('link', { name: 'Export CSV', exact: true })).toHaveAttribute('href', new RegExp(`location_id=${location}(&|$)`));
    await page.getByRole('button', { name: 'Available', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`location_id=${location}(&|$)`));
});

test('full table view and resizing preserve the stock data and keyboard focus', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await render(page);
    const expand = page.getByRole('button', { name: 'Full table view', exact: true });
    await expand.click();
    await expect(page.locator('dialog[open] .stock-record').first().locator('.stock-available .stock-quantity-value')).toHaveText('12');
    await page.keyboard.press('Escape');
    await expect(expand).toBeFocused();
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(page.locator('.stock-record').first().locator('.stock-available .stock-quantity-value')).toHaveText('12');
});

test('stock records remain accessible in Arabic, dark mode, and forced colors', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 1000 });
    await page.addInitScript(() => localStorage.setItem('theme', 'dark'));
    await render(page, 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    expect((await new AxeBuilder({ page }).include('.stock-layout').analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
    await page.emulateMedia({ forcedColors: 'active' });
    await page.locator('.stock-sku a').first().focus();
    await expect(page.locator('.stock-sku a').first()).toBeFocused();
});
