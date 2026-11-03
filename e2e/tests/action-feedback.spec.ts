import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixtures = JSON.parse(readFileSync('e2e/.auth/layout-fixtures.json', 'utf8'));

async function inertiaPage(page: Page, url: string) {
    if (!await page.locator('script[data-page="app"]').count()) await page.goto('/dashboard');
    const initial = JSON.parse((await page.locator('script[data-page="app"]').textContent())!);
    const response = await page.request.get(url, { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': initial.version } });
    expect(response.ok()).toBe(true);
    return response.json();
}

async function rejectAction(page: Page, url: string, payload: Record<string, any>, method: string, errors: Record<string, string>) {
    await page.route(`**${url}`, async route => {
        if (route.request().method() !== method) return route.fallback();
        // A validation response, with no write to even the disposable data.
        await route.fulfill({ status: 200, headers: { 'X-Inertia': 'true' }, json: {
            ...payload, props: { ...payload.props, errors },
        } });
    });
}

test('own-account deletion is explained and permission search has a named empty state', async ({ page }) => {
    const users = await inertiaPage(page, '/users');
    await page.goto(`/users/${users.props.auth.user.id}`);
    await expect(page.getByRole('button', { name: 'Delete User', exact: true })).toBeDisabled();
    await expect(page.getByText('You cannot delete your own account.', { exact: true })).toBeVisible();
    const permissions = page.getByRole('region', { name: 'Effective permissions', exact: true });
    await expect(permissions).toBeVisible();
    await page.getByRole('searchbox', { name: 'Search', exact: true }).fill('no-matching-permission-zzzz');
    await expect(permissions.getByRole('status')).toHaveText('No results found');
});

test('rejected user deletion keeps its confirmation open and focuses the reason', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 568 });
    const users = await inertiaPage(page, '/users');
    const target = users.props.users.data.find(user => user.id !== users.props.auth.user.id);
    expect(target).toBeTruthy();
    const url = `/users/${target.id}`;
    const payload = await inertiaPage(page, url);
    await rejectAction(page, url, payload, 'DELETE', { user: 'This account cannot be deleted while its ownership is being updated.' });
    await page.goto(url);
    const trigger = page.getByRole('button', { name: 'Delete User', exact: true });
    await trigger.click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Delete User', exact: true }).click();
    const error = dialog.getByRole('alert');
    await expect(error).toContainText('ownership is being updated');
    await expect(error).toBeFocused();
    await expect(dialog.getByRole('button', { name: 'Delete User', exact: true })).toBeEnabled();
    await dialog.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
    await trigger.click();
    await expect(page.getByRole('dialog').getByRole('alert')).toHaveCount(0);
});

test('rejected order deletion keeps the error inside the confirmation', async ({ page }) => {
    const url = `/orders/${fixtures.order}`;
    const payload = await inertiaPage(page, url);
    await rejectAction(page, url, payload, 'DELETE', { order: 'This order changed. Reload it before deleting.' });
    await page.goto(url);
    await page.getByRole('button', { name: 'Delete Order', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Delete Order', exact: true }).click();
    await expect(dialog.getByRole('alert')).toHaveText('This order changed. Reload it before deleting.');
    await expect(dialog.getByRole('alert')).toBeFocused();
    await expect(dialog.getByRole('button', { name: 'Delete Order', exact: true })).toBeEnabled();
});

test('rejected approval preserves notes and announces validation in the dialog', async ({ page }) => {
    const url = `/orders/${fixtures.order}`;
    const payload = await inertiaPage(page, url);
    payload.props.canApprove = true;
    payload.props.approvalRequired = true;
    payload.props.order.approval_status = 'pending';
    await page.route(`**${url}`, async route => {
        if (route.request().method() !== 'GET' || !route.request().headers()['x-inertia']) return route.fallback();
        await route.fulfill({ status: 200, headers: { 'X-Inertia': 'true' }, json: payload });
    });
    await rejectAction(page, `${url}/reject`, payload, 'POST', { notes: 'Please provide a more detailed reason.' });
    await page.goto(`/orders?search=${encodeURIComponent(payload.props.order.order_number)}`);
    await page.locator(`tbody a[href$="${url}"]`).first().click();
    await page.getByRole('button', { name: 'Reject Order', exact: true }).click();
    const dialog = page.getByRole('dialog');
    const notes = dialog.getByRole('textbox');
    await notes.fill('Retain these review notes');
    await dialog.getByRole('button', { name: 'Reject', exact: true }).click();
    await expect(dialog.getByRole('alert')).toHaveText('Please provide a more detailed reason.');
    await expect(notes).toHaveValue('Retain these review notes');
    await expect(notes).toHaveAttribute('aria-invalid', 'true');
    await expect(dialog.getByRole('alert')).toBeFocused();
});
