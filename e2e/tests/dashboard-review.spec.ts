import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const product = { id: 990001, sku: 'STAND-ALU', name: 'Aluminium laptop stand', stock: 3, min_stock: 10, category: { name: 'Equipment' }, location: { name: 'Warehouse B' } };
const suggestion = { id: product.id, supplier: 'Fixture supplier', supplier_id: 990001, reorder_point: 8, suggested_quantity: 20, can_quick_reorder: true };

async function open(page: Page, options: { suggestion?: any, product?: any, permissions?: string[], locale?: string } = {}) {
    let state: any;
    const item = options.product || product;
    await page.route('**/dashboard', async route => {
        const response = await route.fetch();
        const html = await response.text();
        const match = html.match(/(<script\b[^>]*\bdata-page="app"[^>]*>)([\s\S]*?)(<\/script>)/)!;
        state = JSON.parse(match[2]);
        state.props.lowStockProducts = [item];
        state.props.reorderSuggestions = options.suggestion === null ? [] : [options.suggestion || suggestion];
        if (options.permissions) state.props.auth = { ...state.props.auth, permissions: options.permissions };
        if (options.locale) state.props.locale = options.locale;
        await route.fulfill({ response, body: html.replace(match[0], `${match[1]}${JSON.stringify(state).replaceAll('<', '\\u003c')}${match[3]}`) });
    });
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    const trigger = page.locator('.dashboard-stock-row').getByRole('button');
    await trigger.click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    return { dialog, trigger, state };
}

for (const width of [320, 375, 768, 1440]) {
    test(`stock review keeps identity, minimum gap, and order quantity distinct at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 812 });
        const { dialog, trigger } = await open(page);
        await expect(dialog.getByRole('heading', { name: product.name })).toBeVisible();
        await expect(dialog).toContainText(product.sku);
        await expect(dialog).toContainText('Warehouse B');
        await expect(dialog.getByRole('img')).toHaveAccessibleName('3 on hand; minimum 10');
        await expect(dialog.locator('.stock-review-shortfall')).toHaveText(/7\s*below minimum/);
        await expect(dialog.locator('.stock-review-order-quantity')).toHaveText(/Suggested qty\s*20/);
        for (const control of await dialog.locator('a, button').all()) expect((await control.boundingBox())!.height).toBeGreaterThanOrEqual(44);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
        await page.keyboard.press('Shift+Tab');
        expect(await dialog.evaluate(e => e.contains(document.activeElement))).toBe(true);
        await page.keyboard.press('Escape');
        await expect(dialog).not.toBeVisible();
        await expect(trigger).toBeFocused();
    });
}

test('a missing supplier has the next permitted action', async ({ page }) => {
    const { dialog } = await open(page, { suggestion: { ...suggestion, supplier: null, supplier_id: null } });
    await expect(dialog.getByText('No supplier', { exact: true })).toBeVisible();
    await expect(dialog.getByRole('link', { name: 'Add a supplier', exact: true })).toHaveAttribute('href', /products\/990001\/edit$/);
    await expect(dialog.getByRole('button', { name: 'Create PO', exact: true })).toHaveCount(0);
});

test('variant selection replaces an ineligible aggregate purchase', async ({ page }) => {
    const { dialog } = await open(page, { suggestion: { ...suggestion, can_quick_reorder: false } });
    await expect(dialog.getByRole('link', { name: 'Choose variants', exact: true })).toHaveAttribute('href', /purchase-orders\/create$/);
    await expect(dialog.getByRole('button', { name: 'Create PO', exact: true })).toHaveCount(0);
    await expect(dialog.locator('.stock-review-order-quantity')).toHaveCount(0);
});

test('view-only and unconfigured records do not imply an available purchase', async ({ page }) => {
    const { dialog } = await open(page, { suggestion: null, permissions: ['view_products'] });
    await expect(dialog.getByRole('link', { name: 'View product', exact: true })).toBeVisible();
    await expect(dialog.getByText('No supplier', { exact: true })).toHaveCount(0);
    await expect(dialog.locator('.stock-review-procurement')).toHaveCount(0);
    await expect(dialog.getByRole('button', { name: 'Create PO', exact: true })).toHaveCount(0);
});

test('a failed create remains in the review and can be retried without duplicate submissions', async ({ page }) => {
    const { dialog, state } = await open(page);
    let release = () => {};
    const gate = new Promise<void>(resolve => { release = resolve; });
    const payloads: unknown[] = [];
    await page.route('**/purchase-orders/quick-reorder', async route => {
        payloads.push(route.request().postDataJSON());
        if (payloads.length === 1) { await gate; return route.abort('failed'); }
        return route.fulfill({ status: 200, headers: { 'Content-Type': 'application/json', 'X-Inertia': 'true' }, json: { ...state, props: { ...state.props, errors: {} } } });
    });
    try {
        const create = dialog.getByRole('button', { name: 'Create PO', exact: true });
        await create.click();
        await expect(create).toBeDisabled();
        await expect.poll(() => payloads.length).toBe(1);
        release();
        await expect(dialog.getByRole('alert')).toHaveText('Could not create the draft purchase order. Try again.');
        await expect(create).toBeEnabled();
        await expect(dialog.getByRole('img')).toHaveAccessibleName('3 on hand; minimum 10');
        await create.click();
        await expect(dialog).not.toBeVisible();
        expect(payloads).toEqual([{ product_ids: [product.id] }, { product_ids: [product.id] }]);
    } finally { release(); }
});

test('server validation stays beside the create action', async ({ page }) => {
    const { dialog, state } = await open(page);
    await page.route('**/purchase-orders/quick-reorder', route => route.fulfill({ status: 200, headers: { 'Content-Type': 'application/json', 'X-Inertia': 'true' }, json: { ...state, props: { ...state.props, errors: { product_ids: 'The supplier changed. Review the product.' } } } }));
    await dialog.getByRole('button', { name: 'Create PO', exact: true }).click();
    await expect(dialog.getByRole('alert')).toHaveText('The supplier changed. Review the product.');
    await expect(dialog.getByRole('button', { name: 'Create PO', exact: true })).toBeEnabled();
});

test('Arabic and large exact quantities remain readable in a narrow review', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 812 });
    const { dialog } = await open(page, { locale: 'ar', product: { ...product, stock: 125000, min_stock: 1000000 } });
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    expect(await dialog.locator('.stock-review').evaluate(e => e.scrollWidth - e.clientWidth)).toBe(0);
    expect((await new AxeBuilder({ page }).include('.stock-review').analyze()).violations).toEqual([]);
});
