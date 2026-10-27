import { test, expect } from '../fixtures';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

test.use({ storageState: { cookies: [], origins: [] } });

test('create a warehouse rule and a variant draft PO at desktop and phone widths', async ({ page }) => {
    const id = randomUUID();
    const output = execFileSync('php', ['artisan', 'db:seed', '--class=E2EVariantReplenishmentSeeder', '--force'], {
        env: { ...process.env, E2E_REPLENISHMENT_ID: id }, encoding: 'utf8', stdio: 'pipe',
    });
    const fixture = JSON.parse(output.match(/REPLENISHMENT_FIXTURE=(.+)/)![1]);
    await page.goto('/login', { waitUntil: 'networkidle' });
    await page.locator('#email').fill(`${id}@replenishment.test`);
    await page.locator('#password').fill('E2EReplenishment123!');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard$/);
    await page.goto('/products');
    await page.getByRole('link', { name: 'Variant replenishment', exact: true }).click();
    await page.getByRole('button', { name: 'Add replenishment rule', exact: true }).click();
    await page.locator('#replenishment-product_variant_id').selectOption(String(fixture.variant));
    const loaded = page.waitForResponse(response => response.url().includes('/variant-replenishment/options?') && response.url().includes(`warehouse_id=${fixture.warehouse}`));
    await page.locator('#replenishment-warehouse_id').selectOption(String(fixture.warehouse));
    await loaded;
    await page.locator('#replenishment-location_id').selectOption(String(fixture.location));
    await page.locator('#replenishment-supplier_id').selectOption(String(fixture.supplier));
    await page.getByLabel(/Unit Cost/).fill('2.50');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Replenishment shirt · Red medium' })).toBeVisible();
    await expect(page.getByText('Receive at Replenishment warehouse / Receiving shelf', { exact: true })).toBeVisible();
    await expect(page.getByText('Manual drafts only', { exact: true })).toBeVisible();
    await page.screenshot({ path: test.info().outputPath('rule-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('button', { name: 'Create draft PO', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: test.info().outputPath('rule-phone.png'), fullPage: true });
    await page.getByRole('button', { name: 'Create draft PO', exact: true }).click();
    await expect(page).toHaveURL(/purchase-orders\/\d+$/);
    await expect(page.getByText('Receive at Replenishment warehouse / Receiving shelf', { exact: true }).first()).toBeVisible();
    await page.goto('/variant-replenishment');
    await expect(page.getByRole('button', { name: 'Create draft PO', exact: true })).toBeDisabled();
    await expect(page.locator('dl').getByText('8', { exact: true })).toBeVisible();
});
