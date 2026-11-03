import { test, expect, type Page, type Route } from '@playwright/test';

const loadFailed = 'Could not load notifications. Please try again.';
const actionFailed = 'Could not update notifications. Please try again.';
const records = Array.from({ length: 6 }, (_, index) => ({
    id: 990001 + index,
    type: 'order_created',
    title: `Notification feedback fixture ${index + 1}`,
    message: 'A notification retained until its update succeeds.',
    priority: 'normal',
    read_at: null,
    action_url: null as string | null,
    created_at: '2026-01-01T12:00:00.000000Z',
}));

const trigger = (page: Page) => page.getByRole('button', { name: /^Notifications: \d+$/ });
const panel = (page: Page) => page.getByRole('region', { name: 'Notifications', exact: true });
const isRecentRequest = (route: Route) => {
    const request = route.request();
    return request.method() === 'GET'
        && new URL(request.url()).pathname === '/notifications'
        && request.headers()['x-inertia-partial-component'] === 'Notifications/Index';
};
const recentPayload = (data = records) => ({ props: { notifications: { data, total: data.length } } });
const json = (route: Route, body: unknown, status = 200) => route.fulfill({ status, json: body });

async function stubUnreadCount(page: Page) {
    await page.route('**/notifications/unread-count', route => json(route, { count: records.length }));
}

async function openFixturePage(page: Page, actionUrl: string | null = null) {
    await stubUnreadCount(page);
    await page.route('**/notifications*', async route => {
        if (isRecentRequest(route)) return json(route, recentPayload());
        if (new URL(route.request().url()).pathname !== '/notifications') return route.fallback();
        const response = await route.fetch();
        const data = await response.json();
        data.props.notifications = { data: [{ ...records[0], action_url: actionUrl }], links: [] };
        data.props.stats = { total: 1, unread: 1, read: 0 };
        data.props.currentFilter = 'all';
        return route.fulfill({ response, json: data });
    });
    await page.goto('/dashboard');
    await trigger(page).click();
    await panel(page).getByRole('link', { name: 'View all', exact: true }).click();
    await expect(page.getByRole('heading', { name: records[0].title, exact: true })).toBeVisible();
}

test('notification dropdown reads the real Inertia response without navigating', async ({ page }) => {
    await page.goto('/dashboard');
    const responsePromise = page.waitForResponse(response => {
        const url = new URL(response.url());
        return url.pathname === '/notifications' && url.searchParams.get('filter') === 'unread';
    });
    await trigger(page).click();
    const response = await responsePromise;
    expect(response.status()).toBe(200);
    expect(response.request().headers()['x-inertia']).toBe('true');
    const data = await response.json();
    expect(Array.isArray(data.props.notifications.data)).toBe(true);
    await expect(panel(page).getByRole('alert')).toHaveCount(0);
    if (data.props.notifications.data.length) {
        await expect(panel(page).getByText(data.props.notifications.data[0].title, { exact: true })).toBeVisible();
    } else {
        await expect(panel(page).getByText('No new notifications', { exact: true })).toBeVisible();
    }
    await expect(page).toHaveURL(/\/dashboard$/);
});

test('failed notification loading is announced, retryable and never presented as an empty inbox', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 568 });
    await stubUnreadCount(page);
    let requests = 0;
    await page.route('**/notifications?*', route => {
        if (!isRecentRequest(route)) return route.fallback();
        requests++;
        return requests === 1 ? json(route, {}, 503) : json(route, recentPayload());
    });
    await page.goto('/dashboard');
    await trigger(page).click();
    await expect(panel(page).getByRole('alert')).toContainText(loadFailed);
    await expect(panel(page).getByText('No new notifications', { exact: true })).toHaveCount(0);
    await panel(page).getByRole('button', { name: 'Retry', exact: true }).click();
    await expect(panel(page).getByRole('alert')).toHaveCount(0);
    await expect(panel(page).getByRole('button', { name: new RegExp(records[0].title) })).toBeVisible();
    await expect(panel(page).getByRole('button', { name: new RegExp(records[5].title) })).toHaveCount(0);
    expect(requests).toBe(2);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
    const bounds = (await panel(page).boundingBox())!;
    expect(bounds.y + bounds.height).toBeLessThanOrEqual(568);
    await page.keyboard.press('Escape');
    await expect(panel(page)).toBeHidden();
    await expect(trigger(page)).toBeFocused();
});

test('reopening notifications while loading shares one request', async ({ page }) => {
    await stubUnreadCount(page);
    let requests = 0;
    let release!: () => void;
    const pending = new Promise<void>(resolve => { release = resolve; });
    await page.route('**/notifications?*', async route => {
        if (!isRecentRequest(route)) return route.fallback();
        requests++;
        await pending;
        return json(route, recentPayload());
    });
    await page.goto('/dashboard');
    await trigger(page).click();
    await expect(panel(page).getByRole('status')).toContainText('Loading...');
    await trigger(page).click();
    await trigger(page).click();
    release();
    await expect(panel(page).getByRole('button', { name: new RegExp(records[0].title) })).toBeVisible();
    expect(requests).toBe(1);
});

test('notification writes cannot duplicate and retain content after a failure', async ({ page }) => {
    await stubUnreadCount(page);
    let marked = false;
    await page.route('**/notifications?*', route => isRecentRequest(route)
        ? json(route, recentPayload(marked ? [] : records)) : route.fallback());
    let posts = 0;
    let release!: () => void;
    const pending = new Promise<void>(resolve => { release = resolve; });
    await page.route(`**/notifications/${records[0].id}/read`, async route => {
        posts++;
        if (posts === 1) {
            await pending;
            return json(route, {}, 503);
        }
        marked = true;
        return json(route, {});
    });
    await page.goto('/dashboard');
    await trigger(page).click();
    const item = panel(page).getByRole('button', { name: new RegExp(records[0].title) });
    await item.click();
    await expect(item).toBeDisabled();
    await expect(item).toHaveAttribute('aria-busy', 'true');
    await item.evaluate(button => (button as HTMLButtonElement).click());
    await expect.poll(() => posts).toBe(1);
    release();
    await expect(panel(page).getByRole('alert')).toContainText(actionFailed);
    await expect(item).toBeEnabled();
    await item.click();
    await expect(panel(page).getByRole('alert')).toHaveCount(0);
    await expect(panel(page).getByText('No new notifications', { exact: true })).toBeVisible();
    expect(posts).toBe(2);
    await expect(trigger(page)).toHaveAccessibleName('Notifications: 0');
});

test('notification page retains records and recovers after a rejected mutation', async ({ page }) => {
    await openFixturePage(page);
    let release!: () => void;
    const pending = new Promise<void>(resolve => { release = resolve; });
    let posts = 0;
    await page.route(`**/notifications/${records[0].id}/read`, async route => {
        posts++;
        await pending;
        return json(route, {}, 503);
    });
    const item = page.getByRole('article').filter({ has: page.getByRole('heading', { name: records[0].title }) });
    const markRead = item.getByRole('button', { name: 'Mark as Read', exact: true });
    await markRead.focus();
    await markRead.click();
    await expect(markRead).toBeDisabled();
    await expect(markRead).toHaveAttribute('aria-busy', 'true');
    await expect(item.getByRole('button', { name: 'Delete', exact: true })).toBeDisabled();
    await expect.poll(() => posts).toBe(1);
    release();
    await expect(page.getByRole('alert')).toContainText(actionFailed);
    await expect(markRead).toBeEnabled();
    await expect(markRead).toBeFocused();
    await expect(item.getByRole('heading')).toBeVisible();
    expect(posts).toBe(1);
});

test('dropdown notification destinations are loaded once after their write succeeds', async ({ page }) => {
    const destination = '/products?notification-feedback=true';
    await stubUnreadCount(page);
    await page.route('**/notifications?*', route => isRecentRequest(route)
        ? json(route, recentPayload([{ ...records[0], action_url: destination }])) : route.fallback());
    let destinationRequests = 0;
    page.on('request', request => {
        if (request.url().endsWith(destination)) destinationRequests++;
    });
    let release!: () => void;
    const pending = new Promise<void>(resolve => { release = resolve; });
    await page.route(`**/notifications/${records[0].id}/read`, async route => {
        await pending;
        return route.fulfill({ status: 303, headers: { Location: destination }, body: '' });
    });
    await page.goto('/dashboard');
    await trigger(page).click();
    const item = panel(page).getByRole('button', { name: new RegExp(records[0].title) });
    await item.click();
    await expect(item).toBeDisabled();
    expect(destinationRequests).toBe(0);
    release();
    await expect(page).toHaveURL(new RegExp(`${destination.replace('?', '\\?')}$`));
    expect(destinationRequests).toBe(1);
});

test('viewing an unread notification waits for its write before following the destination', async ({ page }) => {
    const destination = '/products?notification-feedback=true';
    await openFixturePage(page, destination);
    let posts = 0;
    let destinationRequests = 0;
    let release!: () => void;
    const pending = new Promise<void>(resolve => { release = resolve; });
    page.on('request', request => {
        if (request.url().endsWith(destination)) destinationRequests++;
    });
    await page.route(`**/notifications/${records[0].id}/read`, async route => {
        posts++;
        await pending;
        return route.fulfill({ status: 303, headers: { Location: destination }, body: '' });
    });
    const details = page.getByRole('button', { name: 'View Details', exact: true });
    await details.click();
    await expect(details).toBeDisabled();
    await expect.poll(() => posts).toBe(1);
    expect(destinationRequests).toBe(0);
    await expect(page).toHaveURL(/\/notifications$/);
    release();
    await expect(page).toHaveURL(new RegExp(`${destination.replace('?', '\\?')}$`));
    expect(posts).toBe(1);
    expect(destinationRequests).toBe(1);
});
