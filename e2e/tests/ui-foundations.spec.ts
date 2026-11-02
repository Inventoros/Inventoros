import { test, expect } from '@playwright/test';

for (const [url, trigger] of [
    ['/categories', 'Add Category'], ['/locations', 'Add Location'],
    ['/settings/api-tokens', 'Create token'], ['/webhooks', 'Add Webhook'],
]) {
    test(`${url} dialog traps focus, closes with Escape and restores its trigger`, async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 568 });
        await page.goto(url);
        const actions = page.getByRole('button', { name: 'Actions', exact: true });
        if (await actions.isVisible()) await actions.click();
        const button = page.getByRole('button', { name: trigger, exact: true });
        await button.click();
        const dialog = page.locator('dialog[open]');
        await expect(dialog).toHaveCount(1);
        await expect(dialog).toHaveAccessibleName(/.+/);
        expect(await dialog.evaluate(el => el !== document.activeElement && el.contains(document.activeElement))).toBe(true);
        await page.keyboard.press('Shift+Tab');
        expect(await dialog.evaluate(el => el.contains(document.activeElement))).toBe(true);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(button).toBeFocused();
        expect(await page.evaluate(() => document.body.style.overflow)).toBe('');
    });
}

test('category fields have associated visible labels', async ({ page }) => {
    await page.goto('/categories');
    await page.getByRole('button', { name: 'Add Category', exact: true }).click();
    await expect(page.getByLabel('Category Name', { exact: false })).toBeVisible();
    await expect(page.getByLabel('Description', { exact: true })).toBeVisible();
});

test('a rejected category save preserves input and announces the error', async ({ page }) => {
    await page.goto('/categories');
    await page.getByRole('button', { name: 'Add Category', exact: true }).click();
    const name = page.getByLabel('Category Name', { exact: false });
    const overlongName = 'X'.repeat(256);
    await name.fill(overlongName);
    await page.getByRole('button', { name: 'Create Category', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('255');
    await expect(name).toHaveValue(overlongName);
    await expect(page.getByRole('button', { name: 'Create Category', exact: true })).toBeEnabled();
});

test('language picker supports arrows, Escape, focus restoration and Arabic direction', async ({ page }) => {
    await page.goto('/products');
    const picker = page.locator('button[aria-haspopup="listbox"]');
    await picker.press('ArrowDown');
    await expect(page.locator('[role="option"][lang="en"]')).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('[role="option"][lang="es"]')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('listbox')).toHaveCount(0);
    await expect(picker).toBeFocused();
    await picker.click();
    await page.locator('[role="option"][lang="ar"]').click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await page.goto('/products');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    for (const width of [320, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
    }
    const sidebar = (await page.locator('aside').boundingBox())!;
    const main = (await page.locator('main').boundingBox())!;
    expect(sidebar.x).toBeGreaterThanOrEqual(main.x + main.width - 1);
    await picker.click();
    await page.locator('[role="option"][lang="en"]').click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
});

test('global search shortcut toggles without reopening itself', async ({ page }) => {
    await page.goto('/dashboard');
    await page.keyboard.press('Control+k');
    await expect(page.locator('dialog[open]')).toHaveCount(1);
    await page.keyboard.press('Control+k');
    await expect(page.locator('dialog[open]')).toHaveCount(0);
});
