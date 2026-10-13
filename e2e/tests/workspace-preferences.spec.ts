import { test, expect, Page } from '@playwright/test';
import { E2E_TEST_USER, E2E_STAFF_USER, E2E_TEST_ORGANIZATION } from '../test-credentials';

// Settings change the shared test organization, so run these checks in order.
test.describe.configure({ mode: 'serial' });
test.use({ storageState: { cookies: [], origins: [] } });

async function signIn(page: Page, user = E2E_TEST_USER) {
    await page.goto('/login');
    await page.locator('#email').fill(user.email);
    await page.locator('#password').fill(user.password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await page.waitForURL('**/dashboard');
}

for (const mobile of [false, true]) {
    test(`sign out works for a restricted member on ${mobile ? 'mobile' : 'desktop'}`, async ({ page }) => {
        await page.setViewportSize(mobile ? { width: 390, height: 844 } : { width: 1280, height: 800 });
        await signIn(page, { ...E2E_TEST_USER, ...E2E_STAFF_USER });
        if (mobile) await page.getByRole('button', { name: 'Toggle navigation' }).click();
        await page.getByTestId('logout').click();
        await page.waitForURL((url) => !url.pathname.includes('/dashboard'));
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login$/);
    });
}

test('saved organization currency follows navigation into new forms', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await signIn(page);
    await page.goto('/settings/organization');
    await page.getByRole('button', { name: 'Regional Settings', exact: true }).click();
    const original = await page.locator('#currency').inputValue();
    await page.locator('#currency').selectOption('JPY');
    const save = page.locator('form').filter({ has: page.locator('#currency') }).getByRole('button', { name: 'Save Changes' });
    await save.click();
    await expect(page.getByText('Regional settings updated successfully.')).toBeVisible();

    try {
        // Use Inertia navigation after saving; a full reload must not be needed.
        await page.locator('aside a[href$="/customers"]').click();
        await page.locator('a[href$="/customers/create"]').first().click();
        await expect(page.locator('#currency')).toHaveValue('JPY');
        for (const path of ['/suppliers/create', '/purchase-orders/create', '/warehouses/create']) {
            await page.goto(path);
            await expect(page.locator('#currency')).toHaveValue('JPY');
            await page.locator('#currency').selectOption('BRL');
            await expect(page.locator('#currency')).toHaveValue('BRL');
        }

        // An existing supplier's selected currency still takes precedence.
        await page.goto('/purchase-orders/create');
        const supplier = page.locator('#supplier_id option').filter({ hasText: 'E2E' }).first();
        await page.locator('#supplier_id').selectOption(await supplier.getAttribute('value') as string);
        await expect(page.locator('#currency')).toHaveValue('USD');
    } finally {
        await page.goto('/settings/organization');
        await page.getByRole('button', { name: 'Regional Settings', exact: true }).click();
        await page.locator('#currency').selectOption(original);
        await page.locator('form').filter({ has: page.locator('#currency') }).getByRole('button', { name: 'Save Changes' }).click();
        await expect(page.getByText('Regional settings updated successfully.')).toBeVisible();
    }
});

test('company logo survives reload and can be removed', async ({ page }) => {
    await signIn(page);
    await page.goto('/settings/organization');
    await page.locator('#organization-logo').setInputFiles({
        name: 'company.png', mimeType: 'image/png',
        buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=', 'base64'),
    });
    await page.locator('form').filter({ has: page.locator('#organization-logo') }).getByRole('button', { name: 'Save', exact: true }).click();
    const logo = page.locator('aside img');
    await expect(logo).toHaveAttribute('alt', E2E_TEST_ORGANIZATION.name);
    await expect(logo).toHaveAttribute('src', /\/organizations\/\d+\/logo/);
    await page.reload();
    await expect(logo).toHaveJSProperty('naturalWidth', 1);
    await expect(page.locator('footer img[src*="inventoros"]')).toHaveCount(1);
    await page.getByRole('button', { name: 'Remove logo', exact: true }).click();
    await expect(logo).toHaveCount(0);
});

test('user permission inspection shows granted and ungranted actions', async ({ page }) => {
    await signIn(page);
    await page.goto('/users');
    await page.getByRole('row').filter({ hasText: E2E_STAFF_USER.email }).getByRole('link', { name: 'View', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Effective permissions' })).toBeVisible();
    await page.locator('#permission-search').fill('view_products');
    await expect(page.locator('li').filter({ hasText: 'View Products' })).toContainText('Granted');
    await page.locator('#permission-search').fill('manage_organization');
    await expect(page.locator('li').filter({ hasText: 'Manage Organization' })).toContainText('Not granted');
});
