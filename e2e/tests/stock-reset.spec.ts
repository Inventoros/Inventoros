import { test, expect } from '../fixtures';
import { randomUUID } from 'node:crypto';
import { execFileSync } from 'node:child_process';

test.use({ storageState: { cookies: [], origins: [] } });

test('an administrator can correct a password error and follow a queued stock reset', async ({ page }) => {
    const id = randomUUID();
    execFileSync('php', ['artisan', 'db:seed', '--class=E2EStockResetSeeder', '--force'], {
        env: { ...process.env, E2E_RESET_FIXTURE_ID: id }, stdio: 'pipe',
    });
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/login', { waitUntil: 'networkidle' });
    await page.locator('#email').fill(`${id}@reset.test`);
    await page.locator('#password').fill('E2EResetPassword123!');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard$/);

    await page.goto('/products/stock-reset');
    await page.getByLabel('Reason', { exact: true }).fill('Clearing a test import');
    await page.getByLabel('Current password', { exact: true }).fill('WrongPassword');
    await page.getByLabel('Type ZERO STOCK to confirm', { exact: true }).fill('ZERO STOCK');
    await page.getByRole('button', { name: 'Reset stock to zero', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText(/password/i);
    await expect(page.getByLabel('Type ZERO STOCK to confirm', { exact: true })).toHaveValue('');
    await expect(page.getByLabel('Reason', { exact: true })).toHaveValue('Clearing a test import');
    await page.getByLabel('Current password', { exact: true }).fill('E2EResetPassword123!');
    await page.getByLabel('Type ZERO STOCK to confirm', { exact: true }).fill('ZERO STOCK');
    await page.getByRole('button', { name: 'Reset stock to zero', exact: true }).click();
    await expect(page).toHaveURL(/products\/stock-reset\/status\/[\w-]+$/);
    await expect(page.getByRole('status')).toHaveText('Queued');

    execFileSync('php', ['artisan', 'queue:work', 'inventory-resets', '--queue=inventory-resets',
        '--stop-when-empty', '--max-time=30', '--timeout=900', '--tries=1'], { stdio: 'pipe' });
    await expect(page.getByRole('status')).toHaveText('Stock reset completed', { timeout: 10000 });
    await expect(page.getByText('Clearing a test import', { exact: true })).toBeVisible();
    await page.goto(`/products?search=${encodeURIComponent(`RESET-${id}`)}`);
    await expect(page.locator('tbody tr').filter({ hasText: `RESET-${id}` })).toHaveCount(1);
    expect(errors).toEqual([]);
});
