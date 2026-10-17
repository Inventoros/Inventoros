import { test, expect, Page } from '@playwright/test';

test.beforeEach(async ({ context, baseURL }) => {
    const origin = new URL(baseURL!).origin;
    await context.route('**/*', route =>
        new URL(route.request().url()).origin === origin ? route.continue() : route.abort()
    );
});

async function openSearch(page: Page) {
    const input = page.getByPlaceholder('Search products, orders, customers...');
    // A palette from the preceding Inertia page can still be leaving.
    await expect(input).toHaveCount(0);
    await page.keyboard.press('Control+k');
    await expect(input).toHaveCount(1);
    await expect(input).toBeVisible();
    return input;
}

test('a real product search can be opened with the keyboard and recalled', async ({ page }) => {
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    const input = await openSearch(page);
    const response = page.waitForResponse(response => new URL(response.url()).pathname === '/search');
    await input.fill('SKU-10001');
    const search = await response;
    expect(search.status()).toBe(200);
    const { products } = await search.json();
    const product = products.find(product => product.title === 'Wireless Bluetooth Keyboard');
    expect(product).toBeDefined();
    const result = page.getByRole('button', { name: /Wireless Bluetooth Keyboard/ });
    await expect(result).toBeVisible();

    await input.press('ArrowDown');
    await input.press('Enter');
    await expect(page).toHaveURL(new RegExp(`/products/${product.id}$`));
    await expect(page.getByRole('heading', { name: 'Wireless Bluetooth Keyboard', level: 1, exact: true })).toBeVisible();

    await openSearch(page);
    await expect(page.getByRole('button', { name: /Wireless Bluetooth Keyboard/ })).toBeVisible();
    const recent = await page.evaluate(() => JSON.parse(localStorage.getItem('global-search-recent') || '[]'));
    expect(recent[0]).toMatchObject({ id: product.id, type: 'product' });
});

test('all result categories remain selectable and recent searches retain their record types', async ({ page }) => {
    const categories = [
        { key: 'products', type: 'product', title: 'Palette product', url: '/products' },
        { key: 'orders', type: 'order', title: 'Palette order', url: '/orders' },
        { key: 'customers', type: 'customer', title: 'Palette customer', url: '/customers' },
        { key: 'suppliers', type: 'supplier', title: 'Palette supplier', url: '/suppliers' },
        { key: 'purchase_orders', type: 'purchase_order', title: 'Palette purchase order', url: '/purchase-orders' },
    ];
    const results = Object.fromEntries(categories.map(({ key, ...item }) => [key, [{ ...item, id: 1 }]]));
    await page.route('**/search?**', route => route.fulfill({ json: results }));
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    const input = await openSearch(page);
    await input.fill('palette');
    for (const { title } of categories) {
        await expect(page.getByRole('button', { name: title, exact: true })).toBeVisible();
    }

    await input.press('ArrowDown');
    await input.press('ArrowDown');
    await input.press('Enter');
    await expect(page).toHaveURL(/\/orders$/);
    await expect(page.getByRole('heading', { name: 'Orders', exact: true })).toBeVisible();

    const next = await openSearch(page);
    await expect(page.getByRole('button', { name: 'Palette order', exact: true })).toBeVisible();
    await next.fill('palette');
    await page.getByRole('button', { name: 'Palette product', exact: true }).click();
    await expect(page).toHaveURL(/\/products$/);
    const recent = await page.evaluate(() => JSON.parse(localStorage.getItem('global-search-recent') || '[]'));
    expect(recent.map(({ id, type }) => ({ id, type }))).toEqual([
        { id: 1, type: 'product' },
        { id: 1, type: 'order' },
    ]);
});
