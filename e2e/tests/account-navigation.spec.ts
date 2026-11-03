import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

const english = JSON.parse(readFileSync('resources/js/i18n/locales/en.json', 'utf8'));
const arabic = JSON.parse(readFileSync('resources/js/i18n/locales/ar.json', 'utf8'));

async function openAccount(page: Page, locale: 'en' | 'ar' = 'en', tab = 'profile') {
    // Set only this rendered page's language so keyboard tests do not mutate
    // the shared authenticated user's saved preference or race other specs.
    await page.route('**/settings/account**', async route => {
        if (route.request().method() !== 'GET' || route.request().resourceType() !== 'document') return route.fallback();
        const response = await route.fetch();
        const body = (await response.text())
            .replace(/<html lang="[^"]*" dir="[^"]*">/, `<html lang="${locale}" dir="${locale === 'ar' ? 'rtl' : 'ltr'}">`)
            .replace(/(<script data-page="app" type="application\/json">)([\s\S]*?)(<\/script>)/, (_, start, json, end) => {
                const state = JSON.parse(json);
                return start + JSON.stringify({ ...state, props: { ...state.props, locale } }).replaceAll('<', '\\u003C') + end;
            });
        await route.fulfill({ response, body });
    });
    const writes: string[] = [];
    page.on('request', request => {
        const pathname = new URL(request.url()).pathname;
        if (pathname.startsWith('/settings/account') && ['POST', 'PATCH', 'PUT', 'DELETE'].includes(request.method())) writes.push(pathname);
    });
    await page.goto(`/settings/account?tab=${tab}`);
    const catalog = locale === 'ar' ? arabic : english;
    await expect(page.getByRole('heading', { level: 1, name: catalog.settings.account.title, exact: true })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', locale);
    await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr');
    return { catalog, writes };
}

async function expectActiveSection(page: Page, catalog: typeof english, key: string, focused = false) {
    const label = catalog.settings.account[key];
    const navigation = page.getByRole('tablist', { name: catalog.settings.account.title, exact: true });
    const tab = navigation.getByRole('tab', { name: label, exact: true });
    const panel = page.getByRole('tabpanel', { name: label, exact: true });
    await expect(navigation.getByRole('tab')).toHaveCount(4);
    await expect(navigation.getByRole('tab', { selected: true })).toHaveCount(1);
    await expect(navigation.locator('[role="tab"][tabindex="0"]')).toHaveCount(1);
    await expect(navigation.locator('[role="tab"][tabindex="-1"]')).toHaveCount(3);
    await expect(tab).toHaveAttribute('aria-selected', 'true');
    await expect(tab).toHaveAttribute('tabindex', '0');
    await expect(page.getByRole('tabpanel')).toHaveCount(1);
    await expect(panel).toBeVisible();
    await expect(panel).toHaveAttribute('id', (await tab.getAttribute('aria-controls'))!);
    await expect(panel).toHaveAttribute('aria-labelledby', (await tab.getAttribute('id'))!);
    if (focused) await expect(tab).toBeFocused();
    return tab;
}

test('account sections expose one selected tab and retain an unsaved profile through keyboard navigation', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const { catalog, writes } = await openAccount(page);
    const navigation = page.getByRole('tablist', { name: catalog.settings.account.title, exact: true });
    await expect(navigation).toHaveAttribute('aria-orientation', 'vertical');
    const name = page.getByLabel(catalog.common.name, { exact: true });
    await name.fill('Unsubmitted account draft');

    let current = await expectActiveSection(page, catalog, 'profile');
    await current.press('ArrowDown');
    current = await expectActiveSection(page, catalog, 'password', true);
    await expect(page.getByLabel(catalog.settings.account.currentPassword, { exact: true })).toBeVisible();
    await current.press('ArrowUp');
    current = await expectActiveSection(page, catalog, 'profile', true);
    await expect(name).toHaveValue('Unsubmitted account draft');
    await current.press('End');
    current = await expectActiveSection(page, catalog, 'preferences', true);
    await current.press('Home');
    current = await expectActiveSection(page, catalog, 'profile', true);
    await current.press('ArrowUp');
    current = await expectActiveSection(page, catalog, 'preferences', true);
    await current.press('ArrowDown');
    current = await expectActiveSection(page, catalog, 'profile', true);
    await current.press('Tab');
    await expect(name).toBeFocused();
    await expect(name).toHaveValue('Unsubmitted account draft');
    expect(writes).toEqual([]);
});

test('phone section navigation follows the two-column grid and preserves notification deep links', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    const { catalog, writes } = await openAccount(page, 'en', 'notifications');
    const navigation = page.getByRole('tablist', { name: catalog.settings.account.title, exact: true });
    await expect(navigation).toHaveAttribute('aria-orientation', 'horizontal');
    let current = await expectActiveSection(page, catalog, 'notifications');
    await current.press('ArrowLeft');
    current = await expectActiveSection(page, catalog, 'password', true);
    await current.press('ArrowDown');
    current = await expectActiveSection(page, catalog, 'preferences', true);
    await current.press('ArrowUp');
    current = await expectActiveSection(page, catalog, 'password', true);
    await current.press('Home');
    current = await expectActiveSection(page, catalog, 'profile', true);
    await current.press('End');
    current = await expectActiveSection(page, catalog, 'preferences', true);
    await current.press('ArrowRight');
    await expectActiveSection(page, catalog, 'profile', true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
    expect(writes).toEqual([]);
});

test('resizing account navigation retains the active section and unsaved notification choice', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 900 });
    const { catalog, writes } = await openAccount(page, 'en', 'notifications');
    const navigation = page.getByRole('tablist', { name: catalog.settings.account.title, exact: true });
    await expect(navigation).toHaveAttribute('aria-orientation', 'horizontal');
    await expectActiveSection(page, catalog, 'notifications');
    const notification = page.getByRole('switch', { name: catalog.settings.account.emailNotifications, exact: true });
    const nextValue = (await notification.getAttribute('aria-checked')) !== 'true';
    await notification.click();
    await expect(notification).toHaveAttribute('aria-checked', String(nextValue));

    await page.setViewportSize({ width: 1440, height: 900 });
    await expect(navigation).toHaveAttribute('aria-orientation', 'vertical');
    await expectActiveSection(page, catalog, 'notifications');
    await expect(notification).toHaveAttribute('aria-checked', String(nextValue));
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(navigation).toHaveAttribute('aria-orientation', 'horizontal');
    await expectActiveSection(page, catalog, 'notifications');
    await expect(notification).toHaveAttribute('aria-checked', String(nextValue));
    expect(writes).toEqual([]);
});

test('Arabic section arrows follow visual direction and switch thumbs stay inside their RTL tracks', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const { catalog, writes } = await openAccount(page, 'ar');
    let current = await expectActiveSection(page, catalog, 'profile');
    await current.press('ArrowRight');
    current = await expectActiveSection(page, catalog, 'preferences', true);
    await current.press('ArrowLeft');
    current = await expectActiveSection(page, catalog, 'profile', true);

    await page.setViewportSize({ width: 375, height: 812 });
    await expect(page.getByRole('tablist')).toHaveAttribute('aria-orientation', 'horizontal');
    await current.press('ArrowLeft');
    current = await expectActiveSection(page, catalog, 'password', true);
    await current.press('ArrowRight');
    current = await expectActiveSection(page, catalog, 'profile', true);
    await current.press('ArrowDown');
    await expectActiveSection(page, catalog, 'notifications', true);

    const notification = page.getByRole('switch', { name: catalog.settings.account.emailNotifications, exact: true });
    const thumb = notification.locator('.settings-toggle-thumb');
    const checked = (await notification.getAttribute('aria-checked')) === 'true';
    const before = (await thumb.boundingBox())!;
    await notification.click();
    await expect(notification).toHaveAttribute('aria-checked', String(!checked));
    // In RTL an on thumb occupies the left end; turning it off moves right.
    await expect.poll(async () => ((await thumb.boundingBox())!.x - before.x) * (checked ? 1 : -1)).toBeGreaterThan(0);
    const track = (await notification.boundingBox())!;
    const after = (await thumb.boundingBox())!;
    expect(after.x).toBeGreaterThanOrEqual(track.x);
    expect(after.x + after.width).toBeLessThanOrEqual(track.x + track.width);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
    expect(writes).toEqual([]);
});
