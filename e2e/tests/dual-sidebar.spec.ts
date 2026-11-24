import { expect, test, type Page } from '@playwright/test';

/*
 * The two-level sidebar: a section rail plus a panel with the selected
 * section's pages. Docked side by side from 1280px (collapsible), rail only
 * with a flyout panel from 768px, and a drawer holding both on phones.
 */

const rail = (page: Page) => page.locator('.workspace-rail');
const panel = (page: Page) => page.locator('#workspace-panel');
const railButton = (page: Page, name: string) => rail(page).getByRole('button', { name: new RegExp(`^${name}`) });

async function clearCollapsed(page: Page) {
    await page.evaluate(() => localStorage.removeItem('inventoros.sidebar.collapsed'));
}

async function expectNoSideScroll(page: Page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
}

test('the panel follows the current page and the rail swaps sections without navigating', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/categories', { waitUntil: 'networkidle' });
    await clearCollapsed(page);

    await expect(railButton(page, 'Catalog')).toHaveAttribute('aria-pressed', 'true');
    await expect(panel(page).getByRole('link', { name: 'Categories', exact: true })).toHaveAttribute('aria-current', 'page');
    await expect(panel(page).getByRole('link', { name: 'Dashboard', exact: true })).toHaveCount(0);

    await railButton(page, 'Stock').click();
    await expect(page).toHaveURL(/\/categories$/);
    await expect(railButton(page, 'Stock')).toHaveAttribute('aria-pressed', 'true');
    await expect(panel(page).getByRole('navigation', { name: 'Stock' })).toBeVisible();
    await expect(panel(page).getByRole('link', { name: 'Stock Transfers', exact: true })).toBeVisible();

    await panel(page).getByRole('link', { name: 'Stock Transfers', exact: true }).click();
    await expect(page).toHaveURL(/\/stock-transfers$/);
    await expect(railButton(page, 'Stock')).toHaveAttribute('aria-pressed', 'true');

    // The main column starts where the docked sidebar ends.
    const sidebar = (await page.locator('#workspace-navigation').boundingBox())!;
    const main = (await page.locator('main').boundingBox())!;
    expect(sidebar.width).toBe(272);
    expect(main.x).toBeGreaterThanOrEqual(sidebar.x + sidebar.width - 1);
    await expectNoSideScroll(page);
});

test('rail buttons have names, tooltips on keyboard focus and arrow-key movement', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/dashboard', { waitUntil: 'networkidle' });

    const workspace = railButton(page, 'Workspace');
    await workspace.focus();
    await page.keyboard.press('ArrowDown');
    const catalog = railButton(page, 'Catalog');
    await expect(catalog).toBeFocused();
    const tooltip = catalog.locator('.workspace-rail-tooltip');
    await expect(tooltip).toHaveText('Catalog');
    await expect.poll(() => tooltip.evaluate(el => getComputedStyle(el).opacity)).toBe('1');
    await page.keyboard.press('End');
    await expect(page.getByTestId('user-menu')).toBeFocused();
    await page.keyboard.press('Home');
    await expect(workspace).toBeFocused();

    // Enter picks the section; the panel follows.
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await expect(catalog).toHaveAttribute('aria-pressed', 'true');
    await expect(panel(page).getByRole('link', { name: 'Warehouses', exact: true })).toBeVisible();
});

test('the docked panel collapses to the rail, opens as a flyout and remembers the choice', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await clearCollapsed(page);
    await page.reload({ waitUntil: 'networkidle' });

    await page.getByRole('button', { name: 'Collapse sidebar', exact: true }).click();
    await expect(panel(page)).toBeHidden();
    const width = async () => (await page.locator('#workspace-navigation').boundingBox())!.width;
    expect(await width()).toBe(60);
    await expect(railButton(page, 'Workspace')).toBeFocused();

    await page.reload({ waitUntil: 'networkidle' });
    await expect(panel(page)).toBeHidden();

    // A rail button opens the panel over the page; Escape closes it.
    await railButton(page, 'Stock').click();
    await expect(panel(page)).toBeVisible();
    await expect(railButton(page, 'Stock')).toHaveAttribute('aria-expanded', 'true');
    expect(await width()).toBe(60);
    await page.keyboard.press('Escape');
    await expect(panel(page)).toBeHidden();
    await expect(railButton(page, 'Stock')).toBeFocused();

    // Expand from the flyout docks the panel again.
    await railButton(page, 'Stock').click();
    await page.getByRole('button', { name: 'Expand sidebar', exact: true }).click();
    await expect(panel(page)).toBeVisible();
    expect(await width()).toBe(272);
    await page.reload({ waitUntil: 'networkidle' });
    await expect(panel(page)).toBeVisible();
});

test('tablet widths dock only the rail and use a flyout panel', async ({ page }) => {
    await page.setViewportSize({ width: 1024, height: 768 });
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await clearCollapsed(page);

    await expect(panel(page)).toBeHidden();
    const sidebar = (await page.locator('#workspace-navigation').boundingBox())!;
    expect(sidebar.width).toBe(60);
    await expect(page.getByRole('button', { name: 'Collapse sidebar' })).toHaveCount(0);
    await expectNoSideScroll(page);

    // Re-picking the open section closes it; a click on the page closes it too.
    await railButton(page, 'Workspace').click();
    await expect(panel(page)).toBeVisible();
    await railButton(page, 'Workspace').click();
    await expect(panel(page)).toBeHidden();
    await railButton(page, 'Catalog').click();
    await expect(panel(page)).toBeVisible();
    // An empty part of the top bar, clear of the flyout over the page's left edge.
    await page.locator('.workspace-topbar').click({ position: { x: 500, y: 4 } });
    await expect(panel(page)).toBeHidden();

    // Opened from the keyboard, focus moves into the panel; a link navigates and closes it.
    await railButton(page, 'Workspace').focus();
    await page.keyboard.press('Enter');
    await expect(panel(page).locator('.workspace-nav a[href]').first()).toBeFocused();
    await panel(page).getByRole('link', { name: 'Orders', exact: true }).click();
    await expect(page).toHaveURL(/\/orders$/);
    await expect(panel(page)).toBeHidden();
});

test('phones get one drawer with the rail and the panel', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/dashboard', { waitUntil: 'networkidle' });

    const drawer = page.locator('#workspace-navigation');
    await expect(drawer).toHaveAttribute('inert', '');
    await page.getByRole('button', { name: 'Toggle navigation' }).click();
    await expect(drawer).not.toHaveAttribute('inert', '');
    await expect(rail(page)).toBeVisible();
    await expect(panel(page)).toBeVisible();
    await railButton(page, 'Stock').click();
    await expect(panel(page).getByRole('link', { name: 'Stock Audits', exact: true })).toBeVisible();
    const box = (await drawer.boundingBox())!;
    expect(box.x).toBe(0);
    expect(box.x + box.width).toBeLessThanOrEqual(390);
    await expectNoSideScroll(page);
    await panel(page).getByRole('link', { name: 'Stock Audits', exact: true }).click();
    await expect(page).toHaveURL(/\/stock-audits$/);
    await expect(drawer).toHaveAttribute('inert', '');
});

test('the account menu shows the user, links to the account and closes with Escape', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/dashboard', { waitUntil: 'networkidle' });

    const trigger = page.getByTestId('user-menu');
    await expect(trigger).toHaveAttribute('aria-label', 'Account menu');
    await trigger.focus();
    await page.keyboard.press('Enter');
    const menu = page.locator('#workspace-account-panel');
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Account', exact: true })).toBeFocused();
    await expect(menu.getByTestId('logout')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(trigger).toBeFocused();

    await trigger.click();
    await menu.getByRole('link', { name: 'Account', exact: true }).click();
    await expect(page).toHaveURL(/\/settings\/account/);
    await expect(railButton(page, 'Admin')).toHaveAttribute('aria-pressed', 'true');
});
