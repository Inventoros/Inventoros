import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';

// Opt-in evidence sweep, separate from the fast functional regression suite.
// Violations are recorded rather than hidden by exclusions or disabled rules.
test.skip(!process.env.UI_UX_AUDIT, 'Run with the disposable UI audit workflow');
const out = path.join(process.cwd(), 'audit-artifacts');
const widths = [320, 375, 430, 768, 1024, 1440, 1920];
const locales = ['en', 'es', 'fr', 'de', 'pt-BR', 'it', 'ja', 'ko', 'zh-CN', 'ar', 'ru', 'nl', 'tr', 'pl'];
const manifest = () => JSON.parse(readFileSync('e2e/.auth/ui-audit.json', 'utf8'));

async function inspect(page: Page, name: string, url: string, width: number, axe = false) {
    const errors: string[] = [];
    const onError = (error: Error) => errors.push(error.message);
    page.on('pageerror', onError);
    const response = await page.goto(url, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    const layout = await page.evaluate(() => ({
        overflow: document.documentElement.scrollWidth - innerWidth,
        bodyOverflow: document.documentElement.scrollHeight - innerHeight,
        headings: [...document.querySelectorAll('h1')].filter(e => e.getClientRects().length).map(e => e.textContent?.trim()),
        lang: document.documentElement.lang,
        dir: document.documentElement.dir,
        tables: [...document.querySelectorAll('.table-viewport')].filter(e => e.getClientRects().length).map(e => ({ height: e.clientHeight, width: e.clientWidth, scrollHeight: e.scrollHeight, scrollWidth: e.scrollWidth })),
    }));
    const violations = axe ? (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze()).violations.map(v => ({ id: v.id, impact: v.impact, description: v.description, nodes: v.nodes.map(n => ({ target: n.target, html: n.html, failureSummary: n.failureSummary })) })) : [];
    const key = `${name}-${width}`.replace(/[^a-zA-Z0-9_-]/g, '-');
    if (width === 320 || width === 1440) await page.screenshot({ path: path.join(out, `${key}.png`), fullPage: true, animations: 'disabled', caret: 'hide' });
    const result = { name, url, finalUrl: page.url(), status: response?.status(), width, errors, ...layout, violations };
    writeFileSync(path.join(out, `${key}.json`), JSON.stringify(result, null, 2));
    page.off('pageerror', onError);
    return result;
}

test.beforeEach(async ({ page }) => {
    mkdirSync(out, { recursive: true });
    await page.addInitScript(() => localStorage.setItem('theme', 'light'));
});

for (const width of widths) {
    test(`staff route inventory at ${width}px`, async ({ page }) => {
        test.setTimeout(20 * 60_000);
        await page.setViewportSize({ width, height: width < 768 ? 812 : 1080 });
        const results = [];
        for (const route of manifest().routes) {
            results.push(await inspect(page, route.name, route.url, width, width === 1440));
        }
        // A green evidence run means the sweep executed, not that each finding
        // is resolved. Strict mode is enabled for the final verification run.
        if (process.env.UI_UX_AUDIT_STRICT) {
            expect(results.filter(r => r.status! >= 500 || r.errors.length || r.overflow > 1)).toEqual([]);
        }
    });
}

test('all supported languages across layout families', async ({ page }) => {
    test.setTimeout(20 * 60_000);
    const urls = ['/dashboard', '/products', '/products/create', '/orders', '/settings/account', '/reports'];
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    for (const locale of locales) {
        await page.getByRole('button', { name: /Language:/ }).or(page.locator('button[aria-haspopup="listbox"]')).first().click();
        await page.locator(`[role="option"][lang="${locale}"]`).click();
        await page.waitForLoadState('networkidle');
        for (const width of [320, 1440]) {
            await page.setViewportSize({ width, height: width < 768 ? 812 : 1080 });
            for (const url of urls) await inspect(page, `locale-${locale}-${url.slice(1).replaceAll('/', '-')}`, url, width);
        }
    }
    // Restore the shared fixture preference for other suites.
    await page.locator('button[aria-haspopup="listbox"]').click();
    await page.locator('[role="option"][lang="en"]').click();
});

test('guest and portal routes', async ({ browser }) => {
    test.setTimeout(10 * 60_000);
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, viewport: { width: 1440, height: 1080 } });
    const page = await context.newPage();
    const fx = manifest().layout;
    const guest = ['/login', '/register', '/forgot-password', '/reset-password/audit-preview?email=audit%40example.test', `/portal/${fx.portal_slug}/login`, `/portal/${fx.portal_slug}/forgot-password`, `/portal/${fx.portal_slug}/reset-password/audit-preview?email=${encodeURIComponent(fx.portal_email)}`];
    for (const width of widths) {
        await page.setViewportSize({ width, height: width < 768 ? 812 : 1080 });
        for (const [i, url] of guest.entries()) await inspect(page, `guest-${i}`, url, width, width === 1440);
    }
    await page.goto(`/portal/${fx.portal_slug}/login`, { waitUntil: 'networkidle' });
    await page.locator('input[type="email"]').fill(fx.portal_email);
    await page.locator('input[type="password"]').fill(fx.portal_password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(url => !url.pathname.endsWith('/login'));
    const portal = ['', '/orders', `/orders/${fx.order}`, `/orders/${fx.order}/return`, '/returns', `/returns/${fx.return}`];
    for (const width of widths) {
        await page.setViewportSize({ width, height: width < 768 ? 812 : 1080 });
        for (const [i, url] of portal.entries()) await inspect(page, `portal-${i}`, `/portal/${fx.portal_slug}${url}`, width, width === 1440);
    }
    await context.close();
});
