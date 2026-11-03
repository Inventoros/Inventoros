import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

for (const [url, trigger] of [
    ['/categories', 'Add Category'], ['/locations', 'Add Location'],
    ['/settings/api-tokens', 'Create token'], ['/webhooks', 'Add Webhook'],
]) {
    test(`${url} dialog traps focus, closes with Escape and restores its trigger`, async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 568 });
        await page.goto(url);
        await expect(page.locator('h1')).toBeVisible();
        const actions = page.getByRole('button', { name: 'Actions', exact: true });
        const compactActions = await actions.isVisible();
        if (compactActions) await actions.click();
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
        await expect(compactActions ? actions : button).toBeFocused();
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
    await expect(name).toBeFocused();
    await expect(page.getByRole('button', { name: 'Create Category', exact: true })).toBeEnabled();
});

test('notification polling does not accumulate across Inertia navigation', async ({ page }) => {
    await page.clock.install();
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    let polls = 0;
    page.on('request', request => {
        if (new URL(request.url()).pathname === '/notifications/unread-count') polls++;
    });
    for (const name of ['Inventory', 'Orders', 'Customers']) {
        await page.locator('aside').getByRole('link', { name, exact: true }).click();
        await page.waitForLoadState('networkidle');
    }
    polls = 0;
    const nextPoll = page.waitForResponse(response => new URL(response.url()).pathname === '/notifications/unread-count');
    await page.clock.fastForward(30_001);
    await nextPoll;
    expect(polls).toBe(1);
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
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await page.keyboard.press('Control+k');
    await expect(page.locator('dialog[open]')).toHaveCount(1);
    await page.keyboard.press('Control+k');
    await expect(page.locator('dialog[open]')).toHaveCount(0);
});

test('switching language updates table headings without navigating away', async ({ page }) => {
    const french = JSON.parse(readFileSync('resources/js/i18n/locales/fr.json', 'utf8'));
    await page.goto('/orders');
    await page.locator('button[aria-haspopup="listbox"]').click();
    await page.locator('[role="option"][lang="fr"]').click();
    await expect(page.getByRole('columnheader', { name: french.orders.customer, exact: true })).toBeVisible();
    await page.locator('button[aria-haspopup="listbox"]').click();
    await page.locator('[role="option"][lang="en"]').click();
    await expect(page.getByRole('columnheader', { name: 'Customer', exact: true })).toBeVisible();
});

test('numeric product headings and cells align to the end of their columns', async ({ page }) => {
    await page.goto('/products');
    for (const name of ['Stock', 'Price']) {
        await expect(page.getByRole('columnheader', { name, exact: true })).toHaveCSS('text-align', 'end');
    }
    await expect(page.locator('tbody tr').first().locator('td').nth(5)).toHaveCSS('text-align', 'end');
    await expect(page.locator('tbody tr').first().locator('td').nth(6)).toHaveCSS('text-align', 'end');
});

test('multiword payment badges enclose their text in compact order columns', async ({ page }) => {
    await page.setViewportSize({ width: 1024, height: 900 });
    await page.goto('/orders');
    const badge = page.locator('tbody span.inline-flex').filter({ hasText: /^Partially paid$/ }).first();
    await expect(badge).toBeVisible();
    const layout = await badge.evaluate(element => {
        const range = document.createRange();
        range.selectNodeContents(element);
        const text = range.getBoundingClientRect();
        const bounds = element.getBoundingClientRect();
        return { top: text.top - bounds.top, bottom: text.bottom - bounds.bottom };
    });
    expect(layout.top, 'wrapped badge text starts inside its background').toBeGreaterThanOrEqual(0);
    expect(layout.bottom, 'wrapped badge text ends inside its background').toBeLessThanOrEqual(0);
});

test('long unbroken record titles wrap completely within mobile page headers', async ({ page }) => {
    const { report } = JSON.parse(readFileSync('e2e/.auth/layout-fixtures.json', 'utf8'));
    const reportPath = `/reports/builder/${report}`;
    const title = 'WarehouseInventory'.repeat(12);
    await page.setViewportSize({ width: 320, height: 812 });
    await page.goto('/reports/builder');
    // Change only this browser's response so the shared fixture remains intact.
    await page.route(`**${reportPath}`, async route => {
        const response = await route.fetch();
        const data = await response.json();
        data.props.report.name = title;
        await route.fulfill({ response, json: data });
    });
    await page.locator(`a[href$="${reportPath}"]`).first().click();
    const heading = page.getByRole('heading', { level: 1, name: title, exact: true });
    await expect(heading).toBeVisible();
    for (const width of [320, 375, 430]) {
        await page.setViewportSize({ width, height: 812 });
        const layout = await heading.evaluate(element => {
            const bounds = element.getBoundingClientRect();
            return {
                left: bounds.left,
                right: bounds.right,
                height: bounds.height,
                lineHeight: parseFloat(getComputedStyle(element).lineHeight),
                contentOverflow: element.scrollWidth - element.clientWidth,
                pageOverflow: document.documentElement.scrollWidth - innerWidth,
            };
        });
        expect(layout.left, `${width}px: heading starts inside the viewport`).toBeGreaterThanOrEqual(0);
        expect(layout.right, `${width}px: heading ends inside the viewport`).toBeLessThanOrEqual(width);
        expect(layout.height, `${width}px: title wraps instead of truncating`).toBeGreaterThan(layout.lineHeight);
        expect(layout.contentOverflow, `${width}px: complete title fits its heading`).toBeLessThanOrEqual(1);
        expect(layout.pageOverflow, `${width}px: title does not widen the page`).toBeLessThanOrEqual(1);
    }
});

test('mobile navigation contains keyboard focus and returns it after Escape', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 812 });
    await page.goto('/products');
    const toggle = page.getByRole('button', { name: 'Toggle navigation' });
    await toggle.click();
    const nav = page.locator('#workspace-navigation');
    await expect(nav.locator('a').first()).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(nav.getByRole('button', { name: 'Log Out', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(toggle).toBeFocused();
    await expect(nav).toHaveAttribute('inert', '');
});

test('warehouse selection supports arrows and Escape without changing the filter', async ({ page }) => {
    await page.goto('/products');
    const toggle = page.getByRole('button', { name: 'All Warehouses', exact: true });
    await toggle.press('ArrowDown');
    await expect(page.getByRole('menuitemradio', { name: 'All Warehouses', exact: true })).toBeFocused();
    await page.keyboard.press('End');
    await expect(page.getByRole('menuitemradio').last()).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(toggle).toBeFocused();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
});

test('a click behind an open warehouse menu dismisses it before activating the page', async ({ page }) => {
    await page.goto('/products');
    const add = page.getByRole('link', { name: 'Add Product', exact: true });
    const box = (await add.boundingBox())!;
    await page.getByRole('button', { name: 'All Warehouses', exact: true }).click();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
    await expect(page.getByRole('menu')).toBeHidden();
    await expect(page).toHaveURL(/\/products$/);
    await add.click();
    await expect(page).toHaveURL(/\/products\/create$/);
});
