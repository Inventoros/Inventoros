import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Synthetic variation exercises the presentation without changing shared data.
const products = [
    { id: 990001, sku: 'KEY-001', name: 'Wireless keyboard', stock: 0, min_stock: 20 },
    { id: 990002, sku: 'STAND-002', name: 'Aluminium laptop stand', stock: 0, effective_stock: 3, min_stock: 10 },
    { id: 990003, sku: 'BOX-003', name: 'Corrugated shipping box, reinforced double wall', stock: 125, min_stock: 500 },
];
const widgets = {
    stats_overview: true, revenue_chart: true, low_stock_alerts: true,
    recent_orders: true, stock_by_category: true, recent_products: true, reorder_suggestions: true,
};
const stats = {
    totalProducts: 16, lowStockProducts: 7, totalValue: 94390.62,
    pendingOrders: 3, totalOrders: 9, revenueThisMonth: 2721.85, categories: 5, locations: 4,
};

async function render(page: Page, overrides: Record<string, unknown> = {}) {
    await page.route('**/dashboard', async route => {
        const response = await route.fetch();
        const body = await response.text();
        const match = body.match(/(<script\b[^>]*\bdata-page="app"[^>]*>)([\s\S]*?)(<\/script>)/);
        if (!match) throw new Error('Expected an Inertia document.');
        const data = JSON.parse(match[2]);
        data.props = {
            ...data.props, stats, widgetPreferences: widgets, lowStockProducts: products,
            recentOrders: [], stockByCategory: [], reorderSuggestions: [], recentProducts: [],
            ...overrides,
        };
        const json = JSON.stringify(data).replaceAll('<', '\\u003c');
        await route.fulfill({ response, body: body.replace(match[0], `${match[1]}${json}${match[3]}`) });
    });
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await expect(page.locator('.dashboard')).toBeVisible();
}

for (const width of [320, 375, 768, 1024, 1280, 1440, 1920, 3440]) {
    test(`stockroom dashboard preserves quantities and fits at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await render(page);
        const rows = page.locator('.dashboard-stock-row');
        await expect(rows).toHaveCount(3);
        await expect(rows.nth(0).getByRole('img')).toHaveAccessibleName('0 on hand; minimum 20');
        await expect(rows.nth(1).getByRole('img')).toHaveAccessibleName('3 on hand; minimum 10');
        await expect(rows.nth(2).getByRole('img')).toHaveAccessibleName('125 on hand; minimum 500');
        for (const [index, gap] of [20, 7, 375].entries()) {
            await expect(rows.nth(index).locator('.dashboard-shortfall')).toHaveText(`${gap} below minimum`);
        }
        // The list is capped by the server; the total must not become its length.
        await expect(page.locator('.dashboard-warning-badge')).toHaveText('7 low stock');
        await expect(page.getByTestId('dashboard-metric-value')).toHaveAttribute('title', '$94,390.62');
        expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
        // The meter's fill is the stock there is: 3 of a minimum of 10.
        const meter = await rows.nth(1).getByRole('img').boundingBox();
        const fill = await rows.nth(1).locator('.stock-meter-fill').boundingBox();
        expect(Math.abs(fill!.x - meter!.x)).toBeLessThan(1);
        expect(Math.abs(fill!.width - meter!.width * 0.3)).toBeLessThan(1);
        const position = await page.locator('.dashboard-replenishment').boundingBox();
        const metrics = await page.locator('.dashboard-metrics').boundingBox();
        expect(metrics!.y + metrics!.height).toBeLessThanOrEqual(position!.y);
        for (const metric of await page.locator('.dashboard-metric-value').all()) await expect(metric).toBeInViewport();
        for (const button of await rows.getByRole('button').all()) {
            const box = await button.boundingBox();
            expect(box!.x).toBeGreaterThanOrEqual(position!.x);
            expect(box!.x + box!.width).toBeLessThanOrEqual(position!.x + position!.width);
        }
    });
}

test('hidden stats leave no invented metrics', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await render(page, { widgetPreferences: { ...widgets, stats_overview: false, revenue_chart: false } });
    await expect(page.locator('.dashboard-metric')).toHaveCount(0);
    const panel = await page.locator('.dashboard-replenishment').boundingBox();
    const workbench = await page.locator('.dashboard').boundingBox();
    expect(panel!.width).toBe(workbench!.width);
});

test('summary-only preferences keep the full primary stats strip', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await render(page, { widgetPreferences: { ...widgets, low_stock_alerts: false } });
    await expect(page.locator('.dashboard-replenishment')).toHaveCount(0);
    const metrics = await page.locator('.dashboard-metrics').boundingBox();
    const workbench = await page.locator('.dashboard').boundingBox();
    expect(metrics!.width).toBe(workbench!.width);
    const first = await page.locator('.dashboard-metric').first().boundingBox();
    expect(first!.width).toBeLessThan(metrics!.width / 2);
});

for (const empty of [true, false]) {
    test(`an ${empty ? 'empty' : 'in-stock'} catalog has an honest empty state`, async ({ page }) => {
        await render(page, { stats: { ...stats, totalProducts: empty ? 0 : 16, lowStockProducts: 0 }, lowStockProducts: [] });
        await expect(page.locator('.dashboard-stock-row')).toHaveCount(0);
        await expect(page.locator('.dashboard-replenishment').getByText(empty ? 'No products yet' : 'All products are well stocked!', { exact: true })).toBeVisible();
        if (empty) await expect(page.getByRole('link', { name: 'Add Your First Product', exact: true })).toBeVisible();
        await expect(page.locator('.dashboard-warning-badge')).toHaveCount(0);
    });
}

test('missing permission-gated totals are omitted, never displayed as zero', async ({ page }) => {
    await render(page, { stats: { totalProducts: 3, lowStockProducts: 3 } });
    await expect(page.locator('.dashboard-metric')).toHaveCount(1);
    await expect(page.getByTestId('dashboard-metric-products')).toBeVisible();
    await expect(page.getByTestId('dashboard-metric-value')).toHaveCount(0);
    await expect(page.getByTestId('dashboard-metric-revenue')).toHaveCount(0);
});

test('Arabic reverses the stock meter and preserves narrow hit areas', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 812 });
    await render(page, { locale: 'ar' });
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    const row = page.locator('.dashboard-stock-row').nth(1);
    // The fill grows from the right in a right-to-left layout.
    const meter = await row.getByRole('img').boundingBox();
    const fill = await row.locator('.stock-meter-fill').boundingBox();
    expect(Math.abs(fill!.x + fill!.width - (meter!.x + meter!.width))).toBeLessThan(1);
    expect(Math.abs(fill!.width - meter!.width * 0.3)).toBeLessThan(1);
    expect((await row.getByRole('button').boundingBox())!.height).toBeGreaterThanOrEqual(44);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
});

test('the stock workbench has no Axe violations and remains usable in high contrast', async ({ page }) => {
    await render(page);
    expect((await new AxeBuilder({ page }).include('.dashboard-replenishment').analyze()).violations).toEqual([]);
    await page.emulateMedia({ forcedColors: 'active', reducedMotion: 'reduce' });
    const review = page.locator('.dashboard-stock-row').first().getByRole('button');
    await review.focus();
    await expect(review).toBeFocused();
    // In forced colours the meter keeps a system-colour outline, so it stays visible.
    const border = await page.locator('.stock-meter').first().evaluate(e => getComputedStyle(e).borderTopStyle);
    expect(border).toBe('solid');
});

test('runtime stats extensions retain full-width placements', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await render(page);
    await page.evaluate(() => {
        const sdk = (window as any).Inventoros;
        for (const slot of ['before-stats', 'after-stats']) {
            sdk.registerSlotComponent(`dashboard:${slot}`, {
                render: () => sdk.Vue.h('div', { 'data-testid': `extension-${slot}` }, `Fixture ${slot}`),
            });
        }
    });
    const workbench = await page.locator('.dashboard').boundingBox();
    for (const slot of ['before-stats', 'after-stats']) {
        const extension = page.getByTestId(`extension-${slot}`);
        await expect(extension).toBeVisible();
        expect((await extension.boundingBox())!.width).toBe(workbench!.width);
    }
});
