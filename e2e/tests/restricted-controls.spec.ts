import { test, expect, Page } from '@playwright/test';
import { E2E_STAFF_USER } from '../test-credentials';

/**
 * A Warehouse Staff user (view/edit products, view orders and purchase
 * orders, receive, shipments) must not be shown buttons and links that only
 * lead to a 403: new order, add product, the categories and locations
 * tiles, order and purchase order edit, supplier links, and so on.
 */

// Sign in as the staff user, not the admin the other specs share.
test.use({ storageState: { cookies: [], origins: [] } });

async function signIn(page: Page): Promise<void> {
    await page.goto('/login', { waitUntil: 'networkidle' });
    await page.fill('#email', E2E_STAFF_USER.email);
    await page.fill('#password', E2E_STAFF_USER.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL('/dashboard');
}

async function expectNone(page: Page, selector: string): Promise<void> {
    await expect(page.locator(selector), selector).toHaveCount(0);
}

test.describe('Restricted user controls', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
    });

    test('dashboard shows no create buttons or catalogue tiles the user cannot open', async ({ page }) => {
        await page.goto('/dashboard', { waitUntil: 'networkidle' });
        await expectNone(page, 'a[href$="/orders/create"]');
        await expectNone(page, 'a[href$="/products/create"]');
        await expectNone(page, 'a[href$="/categories"]');
        await expectNone(page, 'a[href$="/locations"]');
    });

    test('orders list and order page offer no create or edit', async ({ page }) => {
        await page.goto(`/orders?search=${encodeURIComponent(E2E_STAFF_USER.orderNumber)}`, { waitUntil: 'networkidle' });
        await expect(page.getByText(E2E_STAFF_USER.orderNumber).first()).toBeVisible();
        await expectNone(page, 'a[href$="/orders/create"]');
        await expectNone(page, 'a[href*="/orders/"][href$="/edit"]');

        await page.getByRole('link', { name: E2E_STAFF_USER.orderNumber }).first().click();
        await page.waitForLoadState('networkidle');
        await expectNone(page, 'a[href*="/orders/"][href$="/edit"]');
        await expectNone(page, 'a[href*="/returns/create"]');
    });

    test('products list and product page offer no add product, supplier or work order links', async ({ page }) => {
        // Searched, so products other specs create do not page it out.
        await page.goto(`/products?search=${encodeURIComponent(E2E_STAFF_USER.productSku)}`, { waitUntil: 'networkidle' });
        await expectNone(page, 'a[href$="/products/create"]');

        await page.locator('a[href*="/products/"]', { hasText: E2E_STAFF_USER.productName }).first().click();
        await page.waitForLoadState('networkidle');
        await expectNone(page, 'a[href*="/suppliers/"]');
        await expectNone(page, 'a[href*="/work-orders/create"]');
    });

    test('purchase orders offer no create, edit or supplier links', async ({ page }) => {
        await page.goto(`/purchase-orders?search=${encodeURIComponent(E2E_STAFF_USER.poNumber)}`, { waitUntil: 'networkidle' });
        await expect(page.getByText(E2E_STAFF_USER.poNumber).first()).toBeVisible();
        await expectNone(page, 'a[href$="/purchase-orders/create"]');
        await expectNone(page, 'a[href*="/purchase-orders/"][href$="/edit"]');

        await page.getByRole('link', { name: E2E_STAFF_USER.poNumber }).first().click();
        await page.waitForLoadState('networkidle');
        await expectNone(page, 'a[href*="/purchase-orders/"][href$="/edit"]');
        await expectNone(page, 'a[href*="/suppliers/"]');
    });
});
