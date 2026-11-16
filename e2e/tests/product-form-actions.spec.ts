import { test, expect, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';

async function expectActionsReachable(page: Page) {
    const submit = page.getByRole('button', { name: 'Create Product', exact: true });
    const cancel = page.getByRole('link', { name: 'Cancel', exact: true });
    for (const control of [submit, cancel]) {
        const box = await control.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.y).toBeGreaterThanOrEqual(0);
        expect(box!.y + box!.height).toBeLessThanOrEqual(page.viewportSize()!.height);
        expect(box!.x).toBeGreaterThanOrEqual(0);
        expect(box!.x + box!.width).toBeLessThanOrEqual(page.viewportSize()!.width);
        if (page.viewportSize()!.width < 640) expect(box!.height).toBeGreaterThanOrEqual(44);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
}

for (const width of [320, 375, 768, 1024, 1440]) {
    test(`product actions stay reachable while scrolling at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 812 });
        await page.goto('/products/create', { waitUntil: 'networkidle' });
        await expectActionsReachable(page);
        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight / 2));
        await expectActionsReachable(page);
        await page.locator('#notes').focus();
        await expect(page.locator('#notes')).toBeInViewport();
        const notes = await page.locator('#notes').boundingBox();
        const actionBar = await page.getByRole('button', { name: 'Create Product', exact: true }).locator('..').boundingBox();
        expect(notes!.y + notes!.height).toBeLessThanOrEqual(actionBar!.y);
        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
        await expectActionsReachable(page);

        // Native validation must still take keyboard focus to the required field.
        await page.getByRole('button', { name: 'Create Product', exact: true }).click();
        await expect(page.locator('#name')).toBeFocused();
        await expect(page.locator('#name')).toBeInViewport();
        await expect(page).toHaveURL(/\/products\/create$/);
    });
}

test('sticky actions create and update once, preserve stock, and show server errors', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    const sku = `FORM-${randomUUID()}`;
    let release = () => {};
    const gate = new Promise<void>(resolve => { release = resolve; });
    let creates = 0;
    await page.route('**/products', async route => {
        if (route.request().method() !== 'POST') return route.continue();
        creates++;
        await gate;
        await route.continue();
    });

    try {
        await page.goto('/products/create');
        await page.locator('#name').fill(sku);
        await page.locator('#sku').fill(sku);
        await page.locator('#price').fill('19.95');
        await page.locator('#stock').fill('7');
        await page.locator('#min_stock').fill('2');
        await page.locator('#category').selectOption({ index: 1 });
        await page.locator('#location').selectOption({ index: 1 });
        const create = page.getByRole('button', { name: 'Create Product', exact: true });
        await create.click();
        await expect(create).toBeDisabled();
        await expect(create).toHaveAttribute('aria-busy', 'true');
        await expect.poll(() => creates).toBe(1);
        release();
        await expect(page).not.toHaveURL(/\/products\/create$/);

        await page.goto(`/products?search=${encodeURIComponent(sku)}`);
        await page.locator('tbody tr').filter({ hasText: sku }).locator('a[href$="/edit"]').click();
        await expect(page).toHaveURL(/\/products\/\d+\/edit$/);
        const editUrl = page.url();
        await expect(page.locator('#stock')).toHaveAttribute('readonly', '');
        await expect(page.locator('#stock')).toHaveValue('7');
        await page.locator('#name').fill(`${sku} updated`);
        await page.getByRole('button', { name: 'Update Product', exact: true }).click();
        await expect(page).not.toHaveURL(editUrl);
        await page.goto(editUrl);
        await expect(page.locator('#name')).toHaveValue(`${sku} updated`);
        await expect(page.locator('#stock')).toHaveValue('7');

        await page.goto('/products/create');
        await page.locator('#name').fill('Duplicate SKU validation');
        await page.locator('#sku').fill(sku);
        await page.locator('#price').fill('19.95');
        await page.locator('#stock').fill('0');
        await page.locator('#min_stock').fill('2');
        await create.click();
        await expect(page.locator('#sku').locator('..')).toContainText('already been taken');
        await expect(page.locator('#sku')).toHaveValue(sku);
        await expect(create).toBeEnabled();
    } finally {
        release();
    }
});
