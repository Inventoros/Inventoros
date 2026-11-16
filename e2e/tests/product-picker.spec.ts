import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const product = { id: 990001, name: 'Industrial shelving unit with adjustable brackets', sku: 'SHELF-001', barcode: '0123456789123', stock: 80, has_variants: false, variants: [], active_variants: [], price: 24.5, purchase_price: 12.75, currency: 'USD', prices: {}, suppliers: [] };
const variant = { id: 990011, title: 'Blue / Large', sku: 'BLUE-L', barcode: '9876543210987', stock: 8, is_active: true, price: 31.5 };
const products = [product, { ...product, id: 990002, sku: 'SHELF-002', barcode: '2222222222222' }, { ...product, id: 990003, name: 'Variant shelf', sku: 'SHELF-VAR', barcode: '3333333333333', has_variants: true, variants: [variant], active_variants: [variant] }, ...Array.from({ length: 1000 }, (_, index) => ({ ...product, id: 991000 + index, name: `Catalog component ${index}`, sku: `PART-${index}`, barcode: `BAR-${index}` }))];
const pages = [
    { path: '/orders/create', id: '#add_product' },
    { path: '/purchase-orders/create', id: '#product' },
    { path: '/stock-transfers/create', id: '[id$="-repeat-1-0"]' },
];

async function render(page: Page, path: string, locale = 'en') {
    await page.route('**' + path, async route => {
        const response = await route.fetch();
        const body = await response.text();
        const match = body.match(/(<script\b[^>]*\bdata-page="app"[^>]*>)([\s\S]*?)(<\/script>)/)!;
        const data = JSON.parse(match[2]);
        Object.assign(data.props, { products, locale, sourceLocationIds: null, locations: [{ id: 1, name: 'Source A' }, { id: 2, name: 'Source B' }] });
        await route.fulfill({ response, body: body.replace(match[0], match[1] + JSON.stringify(data).replaceAll('<', '\\u003c') + match[3]) });
    }, { times: 1 });
    await page.goto(path, { waitUntil: 'networkidle' });
}

for (const { path, id } of pages) {
    test(`${path} searches identifiers, limits rendering, and preserves keyboard selection`, async ({ page }) => {
        await render(page, path);
        const picker = page.locator(id);
        await picker.focus();
        await expect(page.locator('.product-picker-popup').getByRole('option')).toHaveCount(40);
        await expect(page.getByRole('status').filter({ hasText: 'Showing 40 of 1003' })).toBeVisible();
        await picker.fill('shelf-002');
        await expect(page.locator('.product-picker-popup').getByRole('option')).toHaveCount(1);
        await picker.press('ArrowDown');
        const active = await picker.getAttribute('aria-activedescendant');
        expect(active).toBeTruthy();
        await expect(page.locator(`[id="${active}"]`)).toContainText('SHELF-002');
        await picker.press('Enter');
        await expect(picker).toHaveValue(/SHELF-002/);
        await expect(picker).toHaveAttribute('aria-expanded', 'false');
        await picker.focus();
        await picker.fill('9876543210987');
        await expect(page.locator('.product-picker-popup').getByRole('option')).toHaveCount(1);
        await expect(page.locator('.product-picker-popup').getByRole('option')).toContainText('Blue / Large');
        await picker.press('Escape');
        await expect(picker).toHaveValue('');
        await expect(picker).toHaveAttribute('aria-expanded', 'false');
        await picker.click();
        await picker.fill('NO-MATCH-9042');
        await expect(page.locator('.product-picker-popup').getByRole('option')).toHaveCount(0);
        await expect(page.locator('.product-picker-popup').getByRole('status')).toHaveText('No results found');
        await picker.press('Tab');
        await expect(picker).toHaveValue('');
    });
}

for (const width of [320, 375, 768, 1440]) {
    test(`product results remain readable and touchable at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await render(page, '/purchase-orders/create');
        const picker = page.locator('#product');
        await picker.scrollIntoViewIfNeeded();
        await picker.fill('0123456789123');
        const option = page.locator('.product-picker-popup').getByRole('option');
        await expect(option).toBeInViewport();
        const box = await option.boundingBox();
        expect(box!.height).toBeGreaterThanOrEqual(44);
        expect(box!.x + box!.width).toBeLessThanOrEqual(width);
        await option.click();
        await expect(picker).toHaveValue(/SHELF-001/);
        await page.getByRole('button', { name: 'Clear product', exact: true }).click();
        await expect(picker).toBeFocused();
        await expect(picker).toHaveValue('');
        expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
    });
}

test('purchase line selection retains variant validation and cost defaults', async ({ page }) => {
    await render(page, '/purchase-orders/create');
    const picker = page.locator('#product');
    await picker.fill('9876543210987');
    await page.locator('.product-picker-popup').getByRole('option').click();
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.getByText('Choose a variant for this product.', { exact: true })).toBeVisible();
    await page.locator('#variant').selectOption(String(variant.id));
    await expect(page.locator('#unit_cost')).toHaveValue('12.75');
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(picker).toHaveValue('');
    await expect(page.getByText('Variant: Blue / Large', { exact: true })).toBeVisible();
});

test('required transfer product rejects unmatched typed text and repeated picker ids are unique', async ({ page }) => {
    await render(page, '/stock-transfers/create');
    const picker = page.locator(pages[2].id);
    await picker.fill('unmatched-entry');
    expect(await picker.evaluate((element: HTMLInputElement) => element.checkValidity())).toBe(false);
    await picker.fill('SHELF-001');
    await page.locator('.product-picker-popup').getByRole('option').click();
    expect(await picker.evaluate((element: HTMLInputElement) => element.checkValidity())).toBe(true);
    await page.getByRole('button', { name: 'Add Item', exact: true }).click();
    const ids = await page.locator('.product-picker input').evaluateAll(elements => elements.map(element => element.id));
    expect(new Set(ids).size).toBe(2);
    await expect(page.getByText('Choose a source to see availability', { exact: true })).toBeVisible();
});

test('source stock is scoped, clears during changes, and recovers from failure', async ({ page }) => {
    let fail = true;
    await page.route('**/stock-transfers/source-stock*', async route => {
        const location = new URL(route.request().url()).searchParams.get('location_id');
        await route.fulfill({ status: fail ? 500 : 200, contentType: 'application/json', body: JSON.stringify({ data: [{ product_id: product.id, product_variant_id: null, available: location === '1' ? 7 : 2 }] }) });
        fail = false;
    });
    await render(page, '/stock-transfers/create');
    await page.locator(pages[2].id).fill('SHELF-001');
    await page.locator('.product-picker-popup').getByRole('option').click();
    await page.getByLabel('From Location').selectOption('1');
    await expect(page.getByText('Source stock could not be loaded', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Retry', exact: true }).click();
    await expect(page.getByText('Available at source: 7', { exact: true }).first()).toBeVisible();
    await page.getByLabel('From Location').selectOption('2');
    await expect(page.getByText('Available at source: 2', { exact: true }).first()).toBeVisible();
    await page.getByLabel('From Location').selectOption('');
    await expect(page.getByText('Choose a source to see availability', { exact: true })).toBeVisible();
    await expect(page.getByText('Available at source: 2', { exact: true }).first()).toHaveCount(0);
});

test('picker supports RTL, dark mode, forced colors and accessible keyboard focus', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 900 });
    await page.addInitScript(() => localStorage.setItem('theme', 'dark'));
    await render(page, '/purchase-orders/create', 'ar');
    const picker = page.locator('#product');
    await picker.fill('SHELF-001');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(picker).toHaveAttribute('placeholder', 'ابحث بالاسم أو رمز المنتج أو الباركود');
    expect((await new AxeBuilder({ page }).include('.product-picker').analyze()).violations).toEqual([]);
    await page.emulateMedia({ forcedColors: 'active' });
    await picker.press('ArrowDown');
    await expect(page.locator('.product-picker-active')).toBeVisible();
    await picker.press('Enter');
    await expect(picker).toBeFocused();
});
