import { test, expect } from '../fixtures';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

function seedAudit(): string {
    const output = execFileSync('php', ['artisan', 'db:seed', '--class=E2EStockAuditHistorySeeder', '--force'], {
        env: { ...process.env, E2E_AUDIT_HISTORY_ID: randomUUID() }, encoding: 'utf8', stdio: 'pipe',
    });
    const auditId = output.match(/AUDIT_ID=(\d+)/)?.[1];
    expect(auditId).toBeTruthy();
    return auditId!;
}

test('a recount retains the previous count and its notes in history', async ({ page }) => {
    const auditId = seedAudit();
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`/stock-audits/${auditId}`);
    await page.getByRole('button', { name: 'Recount', exact: true }).click();
    await page.locator('input[id^="audit-count-input-"]').fill('4');
    await page.locator('tbody input[type="text"]').fill('Second pass');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.locator('input[id^="audit-count-input-"]')).toHaveCount(0);
    await page.getByRole('link', { name: 'View count history', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Count history', exact: true })).toBeVisible();
    await expect(page.locator('tbody tr')).toHaveCount(2);
    await expect(page.locator('tbody tr').first()).toContainText('Second pass');
    await expect(page.locator('tbody tr').last()).toContainText('First pass');
    expect(errors).toEqual([]);
});

test('a lost response preserves the draft until the newer count is reviewed', async ({ page }) => {
    const auditId = seedAudit();
    await page.goto(`/stock-audits/${auditId}`);
    await page.getByRole('button', { name: 'Recount', exact: true }).click();
    const input = page.locator('input[id^="audit-count-input-"]');
    const itemId = (await input.getAttribute('id'))!.replace('audit-count-input-', '');
    await input.fill('4');
    await page.locator('tbody input[type="text"]').fill('Response lost');
    const endpoint = `**/stock-audits/${auditId}/items/${itemId}/count`;
    await page.route(endpoint, async route => {
        await route.fetch(); // Commit the count but lose its acknowledgement.
        await route.abort('connectionreset');
    });
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Save', exact: true })).toBeEnabled();
    await expect(input).toHaveValue('4');
    await page.unroute(endpoint);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByRole('button', { name: "I've reviewed the saved count", exact: true })).toBeVisible();
    await expect(input).toHaveValue('4');
    await page.goto(`/stock-audits/${auditId}/items/${itemId}/counts`);
    await expect(page.locator('tbody tr')).toHaveCount(2);
    await expect(page.locator('tbody tr').first()).toContainText('Response lost');
});
