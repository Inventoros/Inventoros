import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const routes = [['/settings/organization', 6], ['/import-export', 3], ['/approvals', 2]] as const;

for (const width of [320, 375, 768, 1440]) {
    for (const [path, count] of routes) {
        test(`${path}: sections fit and expose their panels at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 812 });
            await page.goto(path, { waitUntil: 'networkidle' });
            const tabs = page.getByRole('tab');
            await expect(tabs).toHaveCount(count);
            await expect(page.locator('[role="tab"][tabindex="0"]')).toHaveCount(1);
            for (const tab of await tabs.all()) {
                const box = await tab.boundingBox();
                expect(box!.x).toBeGreaterThanOrEqual(0);
                expect(box!.x + box!.width).toBeLessThanOrEqual(width);
                expect(box!.height).toBeGreaterThanOrEqual(44);
                await tab.click();
                await expect(tab).toHaveAttribute('aria-selected', 'true');
                const panel = page.locator(`[id="${await tab.getAttribute('aria-controls')}"]`);
                await expect(panel).toBeVisible();
                await expect(panel).toHaveAttribute('aria-labelledby', (await tab.getAttribute('id'))!);
                await expect(page.getByRole('tabpanel')).toHaveCount(1);
            }
            expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
        });
    }
}

test('local sections use arrows, Home/End, and one keyboard stop before the active panel', async ({ page }) => {
    await page.goto('/settings/organization', { waitUntil: 'networkidle' });
    const tabs = page.getByRole('tab');
    await tabs.first().focus();
    await page.keyboard.press('End');
    await expect(tabs.last()).toBeFocused();
    await expect(tabs.last()).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('Home');
    await expect(tabs.first()).toBeFocused();
    await page.keyboard.press('ArrowRight');
    await expect(tabs.nth(1)).toBeFocused();
    await page.keyboard.press('ArrowLeft');
    await expect(tabs.first()).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('tabpanel')).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(tabs.first()).toBeFocused();
});

test('unsaved organization fields survive section switches', async ({ page }) => {
    await page.goto('/settings/organization', { waitUntil: 'networkidle' });
    await page.locator('#name').fill('Unsaved organization draft');
    await page.getByRole('tab', { name: 'Regional Settings', exact: true }).click();
    await expect(page.locator('#name')).toBeHidden();
    await page.getByRole('tab', { name: 'General Information', exact: true }).click();
    await expect(page.locator('#name')).toHaveValue('Unsaved organization draft');
});

test('selected import files survive dataset switches without being uploaded', async ({ page }) => {
    await page.goto('/import-export', { waitUntil: 'networkidle' });
    const posts: string[] = [];
    page.on('request', request => { if (request.method() === 'POST') posts.push(request.url()); });
    const file = page.getByRole('tabpanel').locator('input[type="file"]').first();
    await file.setInputFiles({ name: 'stock.csv', mimeType: 'text/csv', buffer: Buffer.from('name,sku,price,stock\nSample,EXAMPLE,1,1\n') });
    await page.getByRole('tab', { name: 'Orders', exact: true }).click();
    await page.getByRole('tab', { name: 'Products', exact: true }).click();
    expect(await file.evaluate((element: HTMLInputElement) => element.files?.[0]?.name)).toBe('stock.csv');
    expect(posts).toEqual([]);
});

async function overrideProps(page: Page, path: string, change: (props: any) => void) {
    await page.route(`**${path}`, async route => {
        const response = await route.fetch();
        const body = await response.text();
        const match = body.match(/(<script\b[^>]*\bdata-page="app"[^>]*>)([\s\S]*?)(<\/script>)/);
        if (!match) throw new Error('Expected an Inertia document.');
        const data = JSON.parse(match[2]);
        change(data.props);
        const json = JSON.stringify(data).replaceAll('<', '\\u003c');
        await route.fulfill({ response, body: body.replace(match[0], `${match[1]}${json}${match[3]}`) });
    });
    await page.goto(path, { waitUntil: 'networkidle' });
}

test('organization sections respect the server user-management capability', async ({ page }) => {
    await overrideProps(page, '/settings/organization', props => { props.can.viewUsers = false; });
    await expect(page.getByRole('tab', { name: 'User Management', exact: true })).toHaveCount(0);
    await expect(page.getByRole('tab')).toHaveCount(5);
    await expect(page.getByRole('tabpanel', { includeHidden: true })).toHaveCount(5);
});

test('Import/Export hides unauthorized datasets and falls back from a forbidden requested tab', async ({ page }) => {
    await overrideProps(page, '/import-export?tab=users', props => { props.auth.permissions = ['view_products', 'create_products', 'import_data', 'export_data']; });
    await expect(page.getByRole('tab')).toHaveCount(1);
    await expect(page.getByRole('tab', { name: 'Products', exact: true })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('tabpanel')).toBeVisible();
});

test('Arabic arrows follow visual direction and tabs remain accessible in dark/high contrast', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 812 });
    await page.addInitScript(() => localStorage.setItem('theme', 'dark'));
    await overrideProps(page, '/settings/organization', props => { props.locale = 'ar'; });
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    const tabs = page.getByRole('tab');
    await tabs.first().focus();
    await page.keyboard.press('ArrowLeft');
    await expect(tabs.nth(1)).toBeFocused();
    await page.keyboard.press('ArrowRight');
    await expect(tabs.first()).toBeFocused();
    expect((await new AxeBuilder({ page }).include('.section-tab-list').analyze()).violations).toEqual([]);
    await page.emulateMedia({ forcedColors: 'active' });
    await page.keyboard.press('End');
    await expect(tabs.last()).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
});

test('pending approvals retain their badge and decision notes when sections change', async ({ page }) => {
    await overrideProps(page, '/approvals', props => {
        const item = { id: 990001, type: 'purchase_order', reference: 'PO-UX-1', summary: 'Supplier restock', status: 'pending', can_decide: true, url: '/purchase-orders/990001' };
        props.pending = [item]; props.mine = [{ ...item, can_decide: false }];
    });
    await expect(page.getByRole('tab').first()).toContainText('1');
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    const notes = page.locator('textarea');
    await notes.fill('Verify supplier availability');
    await page.getByRole('tab').nth(1).click();
    await page.getByRole('tab').first().click();
    await expect(page.locator('textarea')).toHaveValue('Verify supplier availability');
    expect(await page.locator('[id="notes-purchase_order:990001"]').count()).toBe(1);
    expect((await new AxeBuilder({ page }).include('.section-tab-list').analyze()).violations).toEqual([]);
});
