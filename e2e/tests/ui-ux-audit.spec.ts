import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

// Opt-in evidence sweep, separate from the fast functional regression suite.
// Violations are recorded rather than hidden by exclusions or disabled rules.
test.skip(!process.env.UI_UX_AUDIT, 'Run with the disposable UI audit workflow');
// Keep evidence sweeps bounded: a failed route is recorded once, then fixed.
test.describe.configure({ retries: 0 });
const out = path.join(process.cwd(), 'audit-artifacts');
const widths = [320, 375, 430, 768, 1024, 1440, 1920];
const locales = ['en', 'es', 'fr', 'de', 'pt-BR', 'it', 'ja', 'ko', 'zh-CN', 'ar', 'ru', 'nl', 'tr', 'pl'];
const manifest = () => JSON.parse(readFileSync('e2e/.auth/ui-audit.json', 'utf8'));

async function inspect(page: Page, name: string, url: string, width: number, axe = false, navigate = true) {
    const errors: string[] = [];
    const onError = (error: Error) => errors.push(error.message);
    page.on('pageerror', onError);
    const response = navigate ? await page.goto(url, { waitUntil: 'networkidle' }) : null;
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
    const result = { name, url, finalUrl: page.url(), status: response?.status() ?? 200, width, errors, ...layout, violations };
    writeFileSync(path.join(out, `${key}.json`), JSON.stringify(result, null, 2));
    page.off('pageerror', onError);
    if (process.env.UI_UX_AUDIT_STRICT) {
        expect.soft(result.status, `${key}: HTTP status`).toBeLessThan(400);
        expect.soft(errors, `${key}: JavaScript exceptions`).toEqual([]);
        expect.soft(layout.overflow, `${key}: document overflow`).toBeLessThanOrEqual(1);
        expect.soft(violations, `${key}: accessibility findings`).toEqual([]);
    }
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
            expect(results.filter(r => r.status >= 400 || r.errors.length || r.overflow > 1 || r.violations.length)).toEqual([]);
        }
    });
}

test('dark theme across staff routes and narrow layout families', async ({ page }) => {
    test.setTimeout(20 * 60_000);
    await page.addInitScript(() => localStorage.setItem('theme', 'dark'));
    await page.setViewportSize({ width: 1440, height: 1080 });
    for (const route of manifest().routes) await inspect(page, `dark-${route.name}`, route.url, 1440, true);
    await page.setViewportSize({ width: 320, height: 812 });
    for (const url of ['/dashboard', '/products', '/products/create', '/orders', '/settings/account', '/reports']) {
        await inspect(page, `dark-${url.slice(1).replaceAll('/', '-')}`, url, 320);
    }
});

test('dialogs, table full view and navigation disclosures', async ({ page }) => {
    test.setTimeout(5 * 60_000);
    for (const width of [320, 1440]) {
        await page.setViewportSize({ width, height: width === 320 ? 568 : 1080 });
        for (const [url, trigger] of [['/categories', 'Add Category'], ['/locations', 'Add Location'], ['/settings/api-tokens', 'Create token'], ['/webhooks', 'Add Webhook']]) {
            await page.goto(url, { waitUntil: 'networkidle' });
            const actions = page.getByRole('button', { name: 'Actions', exact: true });
            if (await actions.isVisible()) await actions.click();
            await page.getByRole('button', { name: trigger, exact: true }).click();
            await expect(page.locator('dialog[open]')).toBeVisible();
            await inspect(page, `dialog-${url.slice(1).replaceAll('/', '-')}`, url, width, true, false);
            await page.keyboard.press('Escape');
        }
        await page.goto('/products', { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: 'Full table view', exact: true }).first().click();
        await inspect(page, 'products-full-view', '/products', width, true, false);
        await page.keyboard.press('Escape');
        await page.locator('button[aria-haspopup="listbox"]').click();
        await inspect(page, 'language-disclosure', '/products', width, true, false);
        await page.keyboard.press('Escape');
        await page.keyboard.press('Control+k');
        await inspect(page, 'global-search-dialog', '/products', width, true, false);
        await page.keyboard.press('Escape');
    }
});

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
    const guest = ['/login', '/register', '/forgot-password', '/reset-password/audit-preview?email=audit%40example.test', `/portal/${fx.portal_slug}/login`, `/portal/${fx.portal_slug}/forgot-password`, `/portal/${fx.portal_slug}/reset-password/audit-preview?email=${encodeURIComponent(fx.portal_email)}`, manifest().states.invitation];
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

test('verification, two-factor, restricted and installer states', async ({ browser }) => {
    test.setTimeout(10 * 60_000);
    const states = manifest().states;
    for (const [name, email, password, url, expected] of [
        ['verify-email', states.unverified, states.password, '/verify-email', 200],
        ['two-factor-challenge', states.mfa, states.password, '/two-factor-challenge', 200],
        ['permission-denied', 'e2e-staff@inventoros.test', 'E2EStaffPassword123!', '/users', 403],
    ] as const) {
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const page = await context.newPage();
        await page.goto('/login');
        await page.locator('#email').fill(email);
        await page.locator('#password').fill(password);
        await page.getByRole('button', { name: 'Log in', exact: true }).click();
        await page.waitForURL(u => u.pathname !== '/login');
        for (const width of widths) {
            await page.setViewportSize({ width, height: width < 768 ? 812 : 1080 });
            // A permission-denied page is an expected 403, not an application failure.
            const strict = process.env.UI_UX_AUDIT_STRICT;
            if (expected === 403) delete process.env.UI_UX_AUDIT_STRICT;
            try {
                const result = await inspect(page, name, url, width, width === 1440);
                expect.soft(result.status).toBe(expected);
                expect.soft(result.errors).toEqual([]);
                expect.soft(result.overflow).toBeLessThanOrEqual(1);
                expect.soft(result.violations).toEqual([]);
            } finally { if (strict) process.env.UI_UX_AUDIT_STRICT = strict; }
        }
        await context.close();
    }
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    const installed = (value: boolean) => execFileSync('php', ['artisan', 'tinker', `--execute=App\\Models\\System\\SystemSetting::set('installed', ${value ? 'true' : 'false'}, 'boolean');`]);
    try {
        installed(false);
        for (const width of widths) {
            await page.setViewportSize({ width, height: width < 768 ? 812 : 1080 });
            for (const [name, url] of [['welcome', '/install'], ['requirements', '/install/requirements'], ['database', '/install/database'], ['admin', '/install/admin']]) {
                await inspect(page, `installer-${name}`, url, width, width === 1440);
            }
        }
        installed(true);
        for (const width of widths) {
            await page.setViewportSize({ width, height: width < 768 ? 812 : 1080 });
            await inspect(page, 'installer-complete', '/install/complete', width, width === 1440);
        }
    } finally { installed(true); await context.close(); }
});
