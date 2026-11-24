import { readFileSync } from 'node:fs';
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const id = () => JSON.parse(readFileSync('e2e/.auth/layout-fixtures.json', 'utf8')).purchase_order;
const url = () => `/purchase-orders/${id()}/receive`;

const fixture = [
    { id: 990001, product_id: 990011, product_name: 'Industrial shelving unit with adjustable brackets', sku: 'SHELF-1001', quantity_ordered: 10, quantity_received: 4, variant: { title: 'Blue / Large' }, receiving_location: { warehouse_id: 1, name: 'Receiving A', warehouse: { name: 'Main warehouse' } } },
    { id: 990002, product_id: 990012, product_name: 'Wireless barcode scanner', sku: 'SCAN-2002', quantity_ordered: 20, quantity_received: 0, receiving_location: { warehouse_id: 2, name: 'Receiving B', warehouse: { name: 'Second warehouse' } } },
    { id: 990003, product_id: 990013, product_name: 'Completed delivery', sku: 'DONE-3003', quantity_ordered: 5, quantity_received: 5 },
];

async function render(page: Page, locale = 'en', large = false) {
    let data: any;
    await page.route(`**${url()}`, async route => {
        const response = await route.fetch();
        const body = await response.text();
        const match = body.match(/(<script\b[^>]*\bdata-page="app"[^>]*>)([\s\S]*?)(<\/script>)/);
        if (!match) throw new Error('Expected an Inertia document.');
        data = JSON.parse(match[2]);
        data.props.purchaseOrder.items = structuredClone(fixture);
        if (large) Object.assign(data.props.purchaseOrder.items[0], { quantity_ordered: 1000000000, quantity_received: 999999999 });
        data.props.locale = locale;
        data.props.receivingLocations = [
            { id: 11, warehouse_id: 1, warehouse_name: 'Main warehouse', code: 'A-1', name: 'Bin one' },
            { id: 12, warehouse_id: 1, warehouse_name: 'Main warehouse', code: 'A-2', name: 'Bin two' },
            { id: 21, warehouse_id: 2, warehouse_name: 'Second warehouse', code: 'B-1', name: 'Bin three' },
        ];
        const json = JSON.stringify(data).replaceAll('<', '\\u003c');
        await route.fulfill({ response, body: body.replace(match[0], `${match[1]}${json}${match[3]}`) });
    }, { times: 1 });
    await page.goto(url(), { waitUntil: 'networkidle' });
    return data;
}

for (const width of [320, 375, 768, 1024, 1440, 1920, 3440]) {
    test(`receiving keeps stock facts and editable fields usable at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await render(page);
        const rows = page.locator('.receiving-table tbody tr');
        await expect(rows).toHaveCount(3);
        await expect(page.getByRole('table')).toHaveCount(1);
        await expect(page.getByRole('columnheader')).toHaveCount(8);
        await expect(page.locator('.receiving-count-value')).toHaveText(['10', '4', '6', '20', '0', '20', '5', '5', '0']);
        await expect(page.getByRole('spinbutton')).toHaveCount(2);
        await expect(rows.nth(2).getByText('Complete', { exact: true })).toBeVisible();
        await expect(rows.first().getByText('Complete', { exact: true })).toHaveCount(0);
        await expect(rows.nth(2).getByRole('spinbutton')).toHaveCount(0);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
        for (const control of await page.locator('.receiving-input').all()) {
            const box = await control.boundingBox();
            expect(box!.x).toBeGreaterThanOrEqual(0);
            expect(box!.x + box!.width).toBeLessThanOrEqual(width);
            if (width <= 1024) expect(box!.height).toBeGreaterThanOrEqual(44);
        }
        if (width <= 1024) {
            expect(await rows.first().evaluate(element => getComputedStyle(element).display)).toBe('grid');
            expect(await page.locator('.receiving-table').evaluate(element => element.scrollWidth - element.clientWidth)).toBe(0);
        }
    });
}

test('prefill actions, cleared quantities, and warehouse-specific bins keep their meanings', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await render(page);
    const quantities = page.getByRole('spinbutton');
    await quantities.first().fill('');
    await quantities.nth(1).fill('2');
    await expect(page.locator('.receiving-summary [role="status"]')).toHaveText(/\b2\b/);
    const locations = page.locator('.receiving-table select');
    await expect(locations.first().locator('option')).toHaveCount(3);
    await expect(locations.nth(1).locator('option')).toHaveCount(2);
    await expect(locations.first()).not.toContainText('Second warehouse');
    await locations.first().selectOption('12');
    await page.locator('.receiving-toolbar').getByRole('button', { name: 'Fill all remaining', exact: true }).click();
    await expect(quantities.first()).toHaveValue('6');
    await expect(quantities.nth(1)).toHaveValue('20');
    await expect(page.locator('.receiving-summary [role="status"]')).toHaveText(/\b26\b/);
    await page.locator('.receiving-toolbar').getByRole('button', { name: 'Clear', exact: true }).click();
    await expect(quantities.first()).toHaveValue('0');
    await expect(quantities.nth(1)).toHaveValue('0');
    await expect(locations.first()).toHaveValue('12');
    await expect(page.getByRole('button', { name: 'Receive', exact: true })).toBeDisabled();
    await page.locator('.receiving-table tbody tr').first().getByRole('button', { name: 'Fill remaining', exact: true }).click();
    await expect(quantities.first()).toHaveValue('6');
    await expect(quantities.nth(1)).toHaveValue('0');
});

test('resizing retains the same editable nodes and choices', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await render(page);
    const quantity = page.getByRole('spinbutton').first();
    await quantity.fill('3');
    const element = await quantity.elementHandle();
    const location = page.locator('.receiving-table select').first();
    await location.selectOption('12');
    await page.setViewportSize({ width: 1440, height: 1000 });
    expect(await element!.evaluate(node => node.isConnected)).toBe(true);
    await expect(quantity).toHaveValue('3');
    await expect(location).toHaveValue('12');
    await expect(page.getByRole('spinbutton')).toHaveCount(2);
    await expect(quantity).toHaveAccessibleDescription(/Industrial shelving/);
});

test('the full table view preserves entered quantities and restores the narrow layout', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await render(page);
    await page.getByRole('spinbutton').first().fill('3');
    await page.getByRole('button', { name: 'Full table view', exact: true }).click();
    await expect(page.locator('dialog[open]').getByRole('spinbutton').first()).toHaveValue('3');
    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(page.getByRole('spinbutton').first()).toHaveValue('3');
    expect(await page.locator('.receiving-table tbody tr').first().evaluate(element => getComputedStyle(element).display)).toBe('grid');
});

test('pending receipts lock editing and send skipped quantities as zero', async ({ page }) => {
    const data = await render(page);
    let release!: () => void;
    const gate = new Promise<void>(resolve => { release = resolve; });
    let posted: any;
    await page.route(`**${url()}`, async route => {
        if (route.request().method() !== 'POST') return route.fallback();
        posted = route.request().postDataJSON();
        await gate;
        await route.fulfill({ status: 200, contentType: 'application/json', headers: { 'X-Inertia': 'true' }, body: JSON.stringify(data) });
    });
    await page.getByRole('spinbutton').first().fill('');
    await page.getByRole('spinbutton').nth(1).fill('2');
    await page.getByRole('button', { name: 'Receive', exact: true }).click();
    try {
        for (const control of await page.locator('.receiving-input').all()) await expect(control).toBeDisabled();
        await expect(page.locator('.receiving-toolbar').getByRole('button', { name: 'Fill all remaining', exact: true })).toBeDisabled();
        await expect(page.getByRole('button', { name: 'Scan Barcode', exact: true })).toBeDisabled();
        expect(posted.items.map((item: any) => item.quantity_to_receive)).toEqual([0, 2, 0]);
    } finally { release(); }
    await expect(page.getByRole('spinbutton').first()).toBeEnabled();
});

test('server quantity validation is shown beside the actual field', async ({ page }) => {
    await page.goto(url(), { waitUntil: 'networkidle' });
    const quantity = page.getByRole('spinbutton').first();
    await quantity.fill('1.5');
    // Exercise the server fallback; normal browser validation blocks this too.
    await page.locator('form').filter({ has: quantity }).evaluate((form: HTMLFormElement) => { form.noValidate = true; });
    await page.getByRole('button', { name: 'Receive', exact: true }).click();
    await expect(quantity).toHaveAttribute('aria-invalid', 'true');
    await expect(quantity).toHaveAccessibleDescription(/integer/i);
});

test('narrow records retain table semantics, exact large counts, RTL, dark and high contrast', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 812 });
    await page.addInitScript(() => localStorage.setItem('theme', 'dark'));
    await render(page, 'ar', true);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('.receiving-table tbody tr').first().locator('.receiving-count-value')).toHaveText(['1000000000', '999999999', '1']);
    expect((await new AxeBuilder({ page }).include('.receiving-items').analyze()).violations).toEqual([]);
    await page.emulateMedia({ forcedColors: 'active' });
    const quantity = page.getByRole('spinbutton').first();
    await quantity.focus();
    await expect(quantity).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
});

test('a long validation error in a receive row wraps instead of widening the table at 1440px', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    const data = await render(page);
    const quantityError = 'The quantity to receive for this line must be a whole number no greater than the quantity still outstanding on the purchase order, and it cannot be negative or left blank when the line is selected.';
    const locationError = 'Choose a receiving bin that belongs to the warehouse this purchase order is being delivered to, or leave the destination empty to keep the existing location for this product.';
    await page.route(`**/purchase-orders/${id()}/receive`, async route => {
        if (route.request().method() !== 'POST') return route.fallback();
        const page = structuredClone(data);
        page.props.errors = { 'items.0.quantity_to_receive': quantityError, 'items.0.receiving_location_id': locationError };
        await route.fulfill({ status: 200, headers: { 'X-Inertia': 'true', Vary: 'X-Inertia' }, contentType: 'application/json', body: JSON.stringify(page) });
    });
    await page.getByRole('spinbutton').first().fill('2');
    await page.locator('form button[type="submit"]').click();

    const row = page.locator('.receiving-table tbody tr').first();
    for (const message of [quantityError, locationError]) {
        const error = row.getByText(message, { exact: true });
        await expect(error).toBeVisible();
        const lines = await error.evaluate(element => Math.round(element.getBoundingClientRect().height / parseFloat(getComputedStyle(element).lineHeight)));
        expect(lines).toBeGreaterThan(1);
    }
    const viewport = page.locator('.table-viewport').filter({ has: page.locator('.receiving-table') });
    expect(await viewport.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
});
