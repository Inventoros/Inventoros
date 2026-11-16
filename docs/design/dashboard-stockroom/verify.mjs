// Compare rendered values to independently calculated raw fixture totals.
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const expected = JSON.parse(readFileSync(new URL('./expected.json', import.meta.url)));
const browser = await chromium.launch();
try {
    const page = await browser.newPage({ storageState: 'e2e/.auth/dashboard-design.json' });
    await page.goto(`${process.env.CAPTURE_BASE_URL || 'http://127.0.0.1:8225'}/dashboard`, { waitUntil: 'networkidle' });
    for (const [key, amount] of [
        ['products', expected.totalProducts], ['value', expected.totalValue],
        ['pending', expected.pendingOrders], ['revenue', expected.revenueThisMonth],
    ]) {
        const exact = await page.getByTestId(`dashboard-metric-${key}`).getAttribute('title');
        assert.equal(Number(exact.replaceAll(/[^\d.-]/g, '')), amount, key);
    }
    const categoryValues = await page.locator('.dashboard-category-facts strong').allTextContents();
    assert.equal(categoryValues.reduce((sum, value) => sum + Math.round(Number(value.replaceAll(/[^\d.-]/g, '')) * 100), 0), Math.round(expected.totalValue * 100));
    const rows = page.locator('.dashboard-stock-row');
    assert.equal(await rows.count(), expected.lowStock.length);
    for (const [index, product] of expected.lowStock.entries()) {
        const text = await rows.nth(index).innerText();
        assert.ok(text.includes(product.sku));
        assert.ok(text.includes(`${product.shortfall} below minimum`));
        assert.equal(await rows.nth(index).getByRole('img').getAttribute('aria-label'), `${product.stock} on hand; minimum ${product.minimum}`);
    }
    assert.equal((await page.locator('.dashboard-warning-badge').innerText()).trim(), `${expected.lowStock.length} Low Stock`);
    console.log('Independent totals, category sum, and all stock quantities match the rendered dashboard.');
} finally {
    await browser.close();
}
