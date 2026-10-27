import { test, expect } from '../fixtures';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

test.use({ storageState: { cookies: [], origins: [] } });

test('off-page scans and drafts use the whole audit without catalogue reloads', async ({ page, context }) => {
    const id = randomUUID();
    const output = execFileSync('php', ['artisan', 'db:seed', '--class=E2EStockAuditHistorySeeder', '--force'], {
        env: { ...process.env, E2E_AUDIT_HISTORY_ID: id, E2E_AUDIT_LINES: '60' }, encoding: 'utf8', stdio: 'pipe',
    });
    const auditId = output.match(/AUDIT_ID=(\d+)/)?.[1];
    expect(auditId).toBeTruthy();
    await page.goto('/login', { waitUntil: 'networkidle' });
    await page.locator('#email').fill(`${id}@history.test`);
    await page.locator('#password').fill('E2EHistoryPassword123!');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard$/);
    await page.goto(`/stock-audits/${auditId}`);
    await expect(page.locator('tbody tr')).toHaveCount(25);

    let catalogueReloads = 0;
    const countReloads = (request: { method(): string; url(): string }) => {
        if (request.method() === 'GET' && new URL(request.url()).pathname === `/stock-audits/${auditId}`) catalogueReloads++;
    };
    page.on('request', countReloads);
    const scanned = page.waitForResponse(response => /\/items\/\d+\/count$/.test(response.url()) && response.request().method() === 'POST');
    await page.getByRole('heading').first().click();
    await page.keyboard.type(`AUDIT-${id}-59`, { delay: 5 });
    await page.keyboard.press('Enter');
    const saved = await (await scanned).json();
    expect(saved.item.counted_quantity).toBe(1);
    expect(saved.summary.counted_items).toBe(2);
    expect(catalogueReloads).toBe(0);
    page.off('request', countReloads);

    await page.getByRole('button', { name: 'Recount', exact: true }).click();
    const input = page.locator('input[id^="audit-count-input-"]');
    const firstId = (await input.getAttribute('id'))!.replace('audit-count-input-', '');
    await context.setOffline(true);
    await input.fill('7');
    await page.locator('tbody input[type="text"]').fill('Offline first-page count');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(input).toHaveCount(0);
    await context.setOffline(false);
    await page.getByRole('link', { name: 'Next', exact: true }).click();
    await expect(page).toHaveURL(/page=2/);
    const refreshed = page.waitForResponse(response => response.url().includes(`/items/${firstId}`) && response.request().method() === 'GET');
    const synced = page.waitForResponse(response => response.url().endsWith(`/items/${firstId}/count`) && response.request().method() === 'POST');
    await page.getByRole('button', { name: /sync/i }).click();
    expect((await refreshed).ok()).toBe(true);
    expect((await (await synced).json()).item.counted_quantity).toBe(7);
    await expect(page.getByRole('button', { name: /sync/i })).toHaveCount(0);

    const confirmation = page.waitForEvent('dialog');
    const click = page.getByRole('button', { name: 'Complete Audit', exact: true }).click();
    const dialog = await confirmation;
    expect(dialog.message()).toMatch(/58 of 60/);
    await dialog.dismiss();
    await click;
});
