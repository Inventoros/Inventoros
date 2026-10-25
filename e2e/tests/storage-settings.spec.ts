import { test, expect } from '../fixtures';

test('installation admin can verify file storage from settings', async ({ page }) => {
    await page.goto('/settings');
    await page.getByRole('link', { name: /File storage/ }).click();
    await expect(page.getByRole('heading', { name: 'File storage', exact: true })).toBeVisible();
    await expect(page.getByText(/Configure INVENTOROS_UPLOADS_DISK/)).toBeVisible();
    const check = page.getByRole('button', { name: 'Check connection', exact: true }).first();
    await check.click();
    await expect(page.getByRole('status').filter({ hasText: 'Connection confirmed: write, read and delete passed.' })).toHaveCount(1);
});
