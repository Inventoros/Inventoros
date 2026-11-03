import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

const english = JSON.parse(readFileSync('resources/js/i18n/locales/en.json', 'utf8'));
const french = JSON.parse(readFileSync('resources/js/i18n/locales/fr.json', 'utf8'));
const arabic = JSON.parse(readFileSync('resources/js/i18n/locales/ar.json', 'utf8'));
const frenchChunk = /\/(?:build\/assets\/fr-[^/]+\.js|resources\/js\/i18n\/locales\/fr\.json)(?:\?.*)?$/;
const additionalLocaleChunk = /\/(?:build\/assets\/(?:ar|de|es|fr|it|ja|ko|nl|pl|pt-BR|ru|tr|zh-CN)-[^/]+\.js|resources\/js\/i18n\/locales\/(?:ar|de|es|fr|it|ja|ko|nl|pl|pt-BR|ru|tr|zh-CN)\.json)(?:\?.*)?$/;

// Exercise real language chunks and Inertia rendering without changing the
// shared test user's server preference while other tests run in parallel.
async function mockPreferenceSave(page: Page) {
    const initial = await page.locator('script[data-page="app"]').textContent();
    const state = JSON.parse(initial!);
    const saved: string[] = [];
    await page.route('**/settings/account/locale', async (route) => {
        const { locale } = route.request().postDataJSON();
        saved.push(locale);
        await route.fulfill({
            status: 200,
            headers: { 'Content-Type': 'application/json', 'X-Inertia': 'true' },
            body: JSON.stringify({ ...state, props: { ...state.props, locale } }),
        });
    });
    return saved;
}

test('downloads a language before changing the interface or saving the preference', async ({ page, context }) => {
    let release = () => {};
    const gate = new Promise<void>((resolve) => { release = resolve; });
    const requests: string[] = [];
    page.on('request', (request) => {
        if (additionalLocaleChunk.test(request.url())) requests.push(request.url());
    });

    try {
        await page.goto('/dashboard', { waitUntil: 'networkidle' });
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        const saved = await mockPreferenceSave(page);
        // Background prefetch must not silently download all thirteen catalogs.
        // Gate only after this check, so a regression reports the extra request
        // instead of deadlocking the initial navigation on our own held route.
        expect(requests).toEqual([]);
        await page.route(frenchChunk, async (route) => {
            await gate;
            await route.continue();
        });
        const trigger = page.locator('button[aria-haspopup="listbox"]');
        await trigger.click();
        await page.locator('[role="option"][lang="fr"]').click();
        await expect(trigger).toHaveAttribute('aria-busy', 'true');
        await expect(page.getByRole('status', { name: english.components.languageSwitcher.ariaLabel.replace('{name}', 'English'), exact: true })).toHaveText(english.common.loading);
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        expect(saved).toEqual([]);
        await expect.poll(() => requests.length).toBe(1);

        release();
        await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
        await expect(trigger).toHaveAttribute('aria-busy', 'false');
        await expect(page.getByRole('heading', { name: french.dashboard.title, exact: true })).toBeVisible();
        await expect.poll(() => saved).toEqual(['fr']);
        expect(await page.evaluate(() => localStorage.getItem('locale'))).toBe('fr');
        expect((await context.cookies()).find(cookie => cookie.name === 'locale')?.value).toBe('fr');

        await trigger.click();
        await page.locator('[role="option"][lang="en"]').click();
        await expect.poll(() => saved).toEqual(['fr', 'en']);
        await trigger.click();
        await page.locator('[role="option"][lang="fr"]').click();
        await expect.poll(() => saved).toEqual(['fr', 'en', 'fr']);
        expect(requests).toHaveLength(1);
    } finally {
        release();
    }
});

test('a failed language download leaves the interface usable and offers a working retry', async ({ page }) => {
    await page.route(frenchChunk, route => route.abort('failed'));
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    const saved = await mockPreferenceSave(page);
    const previous = await page.evaluate(() => localStorage.getItem('locale'));
    const trigger = page.locator('button[aria-haspopup="listbox"]');
    await trigger.click();
    await page.locator('[role="option"][lang="fr"]').click();

    const alert = page.getByRole('alert').filter({ hasText: english.components.languageSwitcher.loadError });
    await expect(alert).toBeVisible();
    await expect(trigger).toHaveAttribute('aria-busy', 'false');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('heading', { name: english.dashboard.title, exact: true })).toBeVisible();
    expect(await page.evaluate(() => localStorage.getItem('locale'))).toBe(previous);
    expect(saved).toEqual([]);

    await page.unroute(frenchChunk);
    await alert.getByRole('button', { name: english.common.retry, exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    await expect(alert).toBeHidden();
    await expect.poll(() => saved).toEqual(['fr']);
});

test('a saved RTL language is ready when the first page becomes visible', async ({ page }) => {
    await page.route('**/dashboard', async (route) => {
        const response = await route.fetch();
        const body = (await response.text())
            .replace(/<html lang="[^"]*" dir="[^"]*">/, '<html lang="ar" dir="rtl">')
            .replace(/(<script data-page="app" type="application\/json">)([\s\S]*?)(<\/script>)/, (_, start, json, end) => {
                const state = JSON.parse(json);
                return start + JSON.stringify({ ...state, props: { ...state.props, locale: 'ar' } }).replaceAll('<', '\\u003C') + end;
            });
        await route.fulfill({ response, body });
    });
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { name: arabic.dashboard.title, exact: true })).toBeVisible();
    await expect(page.locator('button[aria-haspopup="listbox"]')).toHaveAttribute('aria-label', /العربية/);
});
