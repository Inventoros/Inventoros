import { test, expect } from '../fixtures';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

test.use({ storageState: { cookies: [], origins: [] } });

test('recounts preserve evidence and a reviewed variance can complete', async ({ page }) => {
    const fixtureId = randomUUID();
    const output = execFileSync('php', ['artisan', 'db:seed', '--class=E2EStockAuditHistorySeeder', '--force'], {
        env: { ...process.env, E2E_AUDIT_HISTORY_ID: fixtureId, E2E_AUDIT_REVIEW: 'true' }, encoding: 'utf8', stdio: 'pipe',
    });
    const auditId = output.match(/AUDIT_ID=(\d+)/)?.[1];
    expect(auditId).toBeTruthy();
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/login', { waitUntil: 'networkidle' });
    await page.locator('#email').fill(`${fixtureId}@history.test`);
    await page.locator('#password').fill('E2EHistoryPassword123!');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard$/);
    await page.goto(`/stock-audits/${auditId}`);
    const complete = page.getByRole('button', { name: 'Complete Audit', exact: true });
    await expect(complete).toBeDisabled();
    await page.getByRole('button', { name: 'Request recount', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Review notes', { exact: true }).fill('Check the back shelf');
    await dialog.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Recount requested', { exact: true })).toBeVisible();
    await expect(complete).toBeDisabled();

    await page.getByRole('button', { name: 'Recount', exact: true }).click();
    await page.locator('input[id^="audit-count-input-"]').fill('4');
    await page.locator('tbody input[type="text"]').fill('Fresh physical count');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.locator('input[id^="audit-count-input-"]')).toHaveCount(0);
    await expect(complete).toBeDisabled();
    await page.getByRole('button', { name: 'Approve count', exact: true }).click();
    await dialog.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(complete).toBeEnabled();
    page.once('dialog', confirmation => confirmation.accept());
    await complete.click();
    await expect(page.getByText('Completed', { exact: true }).first()).toBeVisible();
    await page.getByRole('link', { name: 'View count history', exact: true }).click();
    await expect(page.locator('tbody tr')).toHaveCount(2);
    await expect(page.locator('tbody tr').first()).toContainText('Approved');
    await expect(page.locator('tbody tr').last()).toContainText('Recount requested');
    expect(errors).toEqual([]);
});
