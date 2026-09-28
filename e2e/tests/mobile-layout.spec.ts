import { execSync } from 'child_process';
import { readFileSync } from 'fs';
import path from 'path';
import { test, expect, type Page } from '@playwright/test';

/**
 * Phone-width layout guard.
 *
 * At 375px nothing may push the page wider than the viewport (header action
 * rows, tab bars, tables, the top-strip warehouse switcher), and no text may be
 * squeezed to one word per line (order line cards, page titles next to a row
 * of actions).
 *
 * E2ELayoutSeeder creates the records these pages need (every header action
 * showing, long product names, a warehouse, a portal contact) and writes their
 * ids to e2e/.auth/layout-fixtures.json.
 */

type Fixtures = {
    order: number;
    order_date: string;
    purchase_order: number;
    return: number;
    report: number;
    portal_slug: string;
    portal_email: string;
    portal_password: string;
};

let fx: Fixtures;

test.describe.configure({ mode: 'serial' });

test.use({ viewport: { width: 375, height: 812 } });

test.beforeAll(() => {
    execSync('php artisan db:seed --class=E2ELayoutSeeder --force', { cwd: process.cwd(), stdio: 'inherit' });
    fx = JSON.parse(readFileSync(path.join(process.cwd(), 'e2e/.auth/layout-fixtures.json'), 'utf8'));
});

async function layoutProblems(page: Page) {
    await page.waitForLoadState('networkidle');

    return page.evaluate(() => {
        const overflow = document.documentElement.scrollWidth - window.innerWidth;

        // A text run of 3+ words that renders one word per line. Table cells are
        // left out: a scrolling table wrapping a long name is expected.
        const squeezed: string[] = [];
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const node = walker.currentNode;
            const words = (node.textContent || '').trim().split(/\s+/).filter(Boolean);
            const parent = node.parentElement;
            if (words.length < 3 || !parent || parent.offsetParent === null || parent.closest('table')) continue;
            const range = document.createRange();
            range.selectNodeContents(node);
            const lines = new Set([...range.getClientRects()].map((r) => Math.round(r.top)));
            if (lines.size >= words.length) squeezed.push(words.join(' '));
        }

        return { overflow, squeezed };
    });
}

async function expectFitsPhone(page: Page) {
    const { overflow, squeezed } = await layoutProblems(page);
    expect(overflow, 'page is wider than a 375px viewport').toBeLessThanOrEqual(0);
    expect(squeezed, 'text squeezed to one word per line').toEqual([]);
}

test.describe('staff pages at 375px', () => {
    const pages: Array<[string, () => string]> = [
        ['dashboard', () => '/dashboard'],
        ['order show', () => `/orders/${fx.order}`],
        ['purchase order show', () => `/purchase-orders/${fx.purchase_order}`],
        ['return show', () => `/returns/${fx.return}`],
        ['report builder show', () => `/reports/builder/${fx.report}`],
        ['organization settings', () => '/settings/organization'],
        ['account settings', () => '/settings/account'],
    ];

    for (const [name, url] of pages) {
        test(`${name} fits the viewport`, async ({ page }) => {
            await page.goto(url());
            await expect(page.locator('h1').first()).toBeVisible();
            await expectFitsPhone(page);
        });
    }

    test('the order show page still fits in French', async ({ page, context, baseURL }) => {
        // Longer labels: the title used to be squeezed one word per line.
        await context.addCookies([{ name: 'locale', value: 'fr', url: baseURL! }]);
        await page.goto(`/orders/${fx.order}`);
        await expect(page.locator('h1').first()).toBeVisible();
        await expectFitsPhone(page);
    });
});

test.describe('customer portal at 375px', () => {
    // West of UTC, where a date-only value parsed as UTC midnight shows the day before.
    test.use({ storageState: { cookies: [], origins: [] }, timezoneId: 'America/Los_Angeles', locale: 'en-US' });

    test('portal order page fits the viewport and shows the order date as written', async ({ page }) => {
        await page.goto(`/portal/${fx.portal_slug}/login`);
        await page.fill('input[type=email]', fx.portal_email);
        await page.fill('input[type=password]', fx.portal_password);
        await Promise.all([
            page.waitForURL((u) => !u.pathname.endsWith('/login')),
            page.keyboard.press('Enter'),
        ]);

        await page.goto(`/portal/${fx.portal_slug}/orders/${fx.order}`);
        await expect(page.locator('h1').first()).toBeVisible();
        await expectFitsPhone(page);

        const [y, m, d] = fx.order_date.split('-').map(Number);
        const expected = new Date(y, m - 1, d).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        await expect(page.locator('header').filter({ has: page.locator('h1') }).first()).toContainText(expected);

        await page.goto(`/portal/${fx.portal_slug}/orders`);
        await expectFitsPhone(page);
    });
});
