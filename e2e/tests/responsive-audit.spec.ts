import { test, expect, type Page } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';

// Opt-in sizing sweep: text that stacks a word per line, identifiers, emails,
// dates and money that wrap, table headers that wrap, squeezed inputs, clipped
// text, document overflow and small phone touch targets. It records findings
// rather than failing, so a run before and after a change can be compared.
//
//   RESPONSIVE_AUDIT=1 RESPONSIVE_AUDIT_LABEL=before \
//     npx playwright test e2e/tests/responsive-audit.spec.ts --project=setup --project=chromium
//
// Output in audit-artifacts/responsive/<label>/: findings-light.json and
// findings-dark.json (summary plus every result), one file per route as it
// finishes under routes-light/ and routes-dark/ (so an interrupted sweep keeps
// what it measured), plus full-page screenshots at 1280px and 375px. Set RESPONSIVE_AUDIT_STRICT=1 to fail on
// any finding other than touch targets.
test.skip(!process.env.RESPONSIVE_AUDIT, 'Run with RESPONSIVE_AUDIT=1');
test.describe.configure({ retries: 0 });

const widths = [375, 768, 1024, 1280, 1440, 1920];
const screenshotWidths = new Set([375, 1280]);
const out = path.join(process.cwd(), 'audit-artifacts', 'responsive', process.env.RESPONSIVE_AUDIT_LABEL || 'latest');

type Finding = { type: string; selector: string; text: string; width: number; detail: string };
type PageResult = { name: string; url: string; width: number; theme: string; overflow: number; tables: number[]; findings: Finding[] };

/** Record ids are looked up from the index pages, so any seeded data works. */
async function firstRecord(page: Page, index: string, pattern: RegExp): Promise<string | null> {
    await page.goto(index, { waitUntil: 'networkidle' });
    const hrefs = await page.locator('main a[href]').evaluateAll(links => links.map(a => new URL((a as HTMLAnchorElement).href).pathname));
    return hrefs.find(href => pattern.test(href)) ?? null;
}

async function settle(page: Page) {
    await page.evaluate(() => document.fonts.ready);
    await page.evaluate(async () => {
        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        // An animation that never finishes (paused, or restarted in a loop)
        // must not hang the sweep: give up waiting after 2s.
        await Promise.race([
            Promise.allSettled(document.getAnimations()
                .filter(animation => animation.effect?.getComputedTiming().iterations !== Infinity)
                .map(animation => animation.finished)),
            new Promise(resolve => setTimeout(resolve, 2000)),
        ]);
    });
}

/** Runs in the page: measures rendered text lines and control sizes. */
function detect({ width, scope }: { width: number; scope?: string }): { overflow: number; findings: Finding[]; tables: number[] } {
    const findings: Finding[] = [];
    const vw = innerWidth;
    const root = (scope && document.querySelector(scope)) || document.body;
    const shown = (el: Element) => {
        if (!root.contains(el)) return false;
        if (el.closest('[aria-hidden="true"], [inert], .sr-only, svg, script, style, noscript, template')) return false;
        // A closed disclosure still lays out its hidden content; skip it.
        const closed = el.closest('details:not([open])');
        if (closed && !el.closest('summary')) return false;
        const style = getComputedStyle(el);
        if (style.visibility === 'hidden' || style.display === 'none') return false;
        const rect = el.getBoundingClientRect();
        if (rect.width < 1 || rect.height < 1) return false;
        // Closed off-canvas drawers sit outside the viewport; scrolled table
        // cells may too, so only skip content outside every scroller.
        const scroller = el.closest('.table-viewport, [class*="overflow-x-auto"], [class*="overflow-auto"]');
        if (!scroller && (rect.right <= 0 || rect.left >= vw)) return false;
        return true;
    };
    const selector = (el: Element) => {
        const parts: string[] = [];
        let node: Element | null = el;
        for (let depth = 0; node && depth < 4 && node !== document.body; depth++) {
            let part = node.tagName.toLowerCase();
            if (node.id && !/^(v-|headlessui|table-view)/.test(node.id)) {
                parts.unshift(`${part}#${node.id}`);
                break;
            }
            const classes = [...node.classList].filter(c => !/[:[\]/]/.test(c)).slice(0, 2);
            if (classes.length) part += '.' + classes.join('.');
            if (['td', 'th', 'li'].includes(part.split('.')[0]) && node.parentElement) {
                part += `:nth-child(${[...node.parentElement.children].indexOf(node) + 1})`;
            }
            parts.unshift(part);
            node = node.parentElement;
        }
        return parts.join(' > ');
    };
    // Count rendered lines from the text boxes, merging boxes that share a line.
    const lineCount = (el: Element) => {
        const rects: DOMRect[] = [];
        const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
        const range = document.createRange();
        for (let text = walker.nextNode(); text; text = walker.nextNode()) {
            if (!text.textContent?.trim() || !shown(text.parentElement!)) continue;
            range.selectNodeContents(text);
            rects.push(...[...range.getClientRects()].filter(r => r.width > 0.5 && r.height > 0));
        }
        rects.sort((a, b) => a.top - b.top);
        const lines: { top: number; bottom: number }[] = [];
        for (const r of rects) {
            const last = lines[lines.length - 1];
            if (last && r.top < last.bottom - Math.min(r.height, last.bottom - last.top) / 2) {
                last.bottom = Math.max(last.bottom, r.bottom);
            } else {
                lines.push({ top: r.top, bottom: r.bottom });
            }
        }
        return lines.length;
    };
    // Inline text reports the width of the box it wraps in.
    const boxWidth = (el: Element) => {
        let node: Element | null = el;
        while (node && getComputedStyle(node).display === 'inline') node = node.parentElement;
        return Math.round((node || el).getBoundingClientRect().width);
    };
    const add = (type: string, el: Element, detail: string) => findings.push({
        type,
        selector: selector(el),
        text: (el.textContent || (el as HTMLInputElement).value || el.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim().slice(0, 80),
        width,
        detail,
    });

    const token = [
        /^[A-Z]{2,}-[\w-]+$/, // ORD-20261123-0001, AF-2001, PO-1048
        /^[^\s@]+@[^\s@]+\.[^\s@]+$/, // emails
        /^[A-Z][a-z]{2} \d{1,2}, \d{4}$/, // Nov 23, 2026
        /^\d{4}-\d{2}-\d{2}$/, // ISO dates
        /^\d{1,2}\/\d{1,2}\/\d{2,4}$/, // 11/23/2026
        /^-?[$€£¥]\s?-?[\d,]+(\.\d+)?$/, // $1,249.99
        /^-?[\d,]+(\.\d+)?\s?[A-Z]{3}$/, // 1,249.99 USD
    ];

    // Elements whose own text renders as 3+ lines in a narrow box, and single
    // tokens that wrap anywhere on the page.
    const seen = new Set<Element>();
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    for (let text = walker.nextNode(); text; text = walker.nextNode()) {
        const value = text.textContent?.replace(/\s+/g, ' ').trim();
        const parent = text.parentElement;
        if (!value || !parent || seen.has(parent) || !shown(parent)) continue;
        if (parent.closest('option, select, textarea, pre, code, .whitespace-pre-wrap')) continue;
        seen.add(parent);
        const lines = lineCount(parent);
        const boxW = boxWidth(parent);
        const ownText = [...parent.childNodes].filter(n => n.nodeType === Node.TEXT_NODE).map(n => n.textContent).join(' ').replace(/\s+/g, ' ').trim();
        if (lines >= 3 && boxW < 140 && ownText.split(' ').length >= 2) {
            add('stacked-text', parent, `${lines} lines in ${boxW}px`);
        }
        if (lines > 1 && token.some(pattern => pattern.test(ownText))) {
            add('token-wrap', parent, `"${ownText}" on ${lines} lines in ${boxW}px`);
        }
    }

    for (const th of root.querySelectorAll('th')) {
        if (!shown(th)) continue;
        const lines = lineCount(th);
        if (lines > 1) add('header-wrap', th, `${lines} lines in ${Math.round(th.getBoundingClientRect().width)}px`);
    }

    const controls = 'input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=range]):not([type=color]):not([type=file]), select, textarea';
    for (const control of root.querySelectorAll(controls)) {
        if (!shown(control)) continue;
        const w = control.getBoundingClientRect().width;
        if (w < 120) add('narrow-input', control, `${Math.round(w)}px wide (${control.getAttribute('name') || control.id || control.getAttribute('type') || control.tagName.toLowerCase()})`);
    }

    // Text cut off without an ellipsis, or truncated with no way to read it.
    for (const el of root.querySelectorAll('*')) {
        if (!(el instanceof HTMLElement) || el.children.length > 2 || !shown(el)) continue;
        const ownText = [...el.childNodes].some(n => n.nodeType === Node.TEXT_NODE && n.textContent?.trim());
        if (!ownText || el.scrollWidth <= el.clientWidth + 1) continue;
        const style = getComputedStyle(el);
        if (!['hidden', 'clip'].includes(style.overflowX)) continue;
        if (style.textOverflow === 'ellipsis') {
            if (!el.closest('[title]')) add('truncated-no-title', el, `${el.scrollWidth}px text in ${el.clientWidth}px`);
        } else {
            add('clipped', el, `${el.scrollWidth}px text in ${el.clientWidth}px`);
        }
    }

    if (width < 768) {
        const targets = 'a[href], button, [role="button"], [role="tab"], [role="menuitem"], input[type=checkbox], input[type=radio], summary';
        for (const target of root.querySelectorAll(targets)) {
            if (!shown(target) || target.closest('p, li > p, td p')) continue;
            const rect = target.getBoundingClientRect();
            if (rect.height < 44 || rect.width < 44) {
                // Inline links in running text are exempt (WCAG 2.5.8).
                const display = getComputedStyle(target).display;
                if (display === 'inline' && target.closest('p')) continue;
                add('touch-target', target, `${Math.round(rect.width)}x${Math.round(rect.height)}px`);
            }
        }
    }

    // Scrolling sideways is the intended fallback for a table that cannot fit,
    // so it is recorded rather than flagged: how far each table overruns.
    const tables = [...root.querySelectorAll('.table-viewport')]
        .filter(shown)
        .map(viewport => viewport.scrollWidth - viewport.clientWidth);

    const overflow = document.documentElement.scrollWidth - innerWidth;
    return { overflow, findings, tables };
}

async function measure(page: Page, route: Route, width: number, theme: string): Promise<PageResult> {
    const name = route.name;
    await settle(page);
    const { overflow, findings, tables } = await page.evaluate(detect, { width, scope: route.scope });
    if (overflow > 1) findings.push({ type: 'document-overflow', selector: 'html', text: '', width, detail: `${overflow}px wider than the viewport` });
    const key = `${theme === 'dark' ? 'dark-' : ''}${name}-${width}`.replace(/[^a-zA-Z0-9_-]/g, '-');
    if (screenshotWidths.has(width) || theme === 'dark') {
        await page.screenshot({ path: path.join(out, `${key}.png`), fullPage: true, animations: 'disabled', caret: 'hide' });
        // Fixed chrome repeats in full-page captures; also keep the working view.
        if (route.focus) await page.locator(route.focus).first().scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(out, `${key}-view.png`), animations: 'disabled', caret: 'hide' });
    }
    return { name, url: new URL(page.url()).pathname, width, theme, overflow, tables, findings };
}

/** scope limits the checks to an open dialog; focus is scrolled into view for the working-view capture. */
type Route = { name: string; url: string; prepare?: (page: Page) => Promise<void>; scope?: string; focus?: string };

/** The order create page with two line items, as the README demo records it. */
async function addOrderLines(page: Page) {
    const customer = page.locator('input[role="combobox"]').first();
    await customer.click();
    await customer.pressSequentially('a', { delay: 20 });
    const option = page.getByRole('option').first();
    await option.waitFor({ state: 'visible', timeout: 5000 });
    await option.click();
    const product = page.locator('#add_product');
    const add = page.getByRole('button', { name: /^Add/ }).first();
    for (let index = 0; index < 2; index++) {
        await product.click();
        await product.fill('');
        await product.pressSequentially(index === 0 ? 'Chair' : 'Desk', { delay: 20 });
        const choice = page.locator('.product-picker-popup').getByRole('option').first();
        const found = await choice.waitFor({ state: 'visible', timeout: 4000 }).then(() => true, () => false);
        if (!found) {
            await product.fill('');
            await product.pressSequentially('a', { delay: 20 });
            await page.locator('.product-picker-popup').getByRole('option').nth(index).click();
        } else {
            await choice.click();
        }
        await add.click();
    }
    await expect(page.locator('#add_product')).toBeVisible();
}

async function openSearch(page: Page) {
    await page.keyboard.press('Control+k');
    const input = page.locator('[role="dialog"] input, dialog[open] input').first();
    await input.waitFor({ state: 'visible' });
    await input.pressSequentially('a', { delay: 30 });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(400);
}

async function routes(page: Page): Promise<Route[]> {
    const product = await firstRecord(page, '/products', /^\/products\/\d+$/);
    const order = await firstRecord(page, '/orders', /^\/orders\/\d+$/);
    const customer = await firstRecord(page, '/customers', /^\/customers\/\d+$/);
    const purchaseOrder = await firstRecord(page, '/purchase-orders', /^\/purchase-orders\/\d+$/);
    const list: (Route | null)[] = [
        { name: 'dashboard', url: '/dashboard' },
        { name: 'products-index', url: '/products' },
        product ? { name: 'products-show', url: product } : null,
        { name: 'products-create', url: '/products/create' },
        product ? { name: 'products-edit', url: `${product}/edit` } : null,
        { name: 'orders-index', url: '/orders' },
        { name: 'orders-create', url: '/orders/create', prepare: addOrderLines, focus: '#add_product' },
        order ? { name: 'orders-show', url: order } : null,
        order ? { name: 'orders-edit', url: `${order}/edit`, focus: '[id^="quantity-"]' } : null,
        { name: 'customers-index', url: '/customers' },
        customer ? { name: 'customers-show', url: customer } : null,
        { name: 'purchase-orders-index', url: '/purchase-orders' },
        { name: 'purchase-orders-create', url: '/purchase-orders/create', focus: '#quantity' },
        purchaseOrder ? { name: 'purchase-orders-show', url: purchaseOrder } : null,
        { name: 'suppliers-index', url: '/suppliers' },
        { name: 'stock-adjustments-index', url: '/stock-adjustments' },
        { name: 'stock-transfers-index', url: '/stock-transfers' },
        { name: 'warehouses-index', url: '/warehouses' },
        { name: 'returns-index', url: '/returns' },
        { name: 'reports-index', url: '/reports' },
        { name: 'reports-sales-analysis', url: '/reports/sales-analysis' },
        { name: 'settings-organization', url: '/settings/organization' },
        { name: 'global-search', url: '/products', prepare: openSearch, scope: 'dialog[open], [role="dialog"]' },
    ];
    return list.filter((route): route is Route => route !== null);
}

async function sweep(page: Page, theme: 'light' | 'dark', sweepWidths: number[]) {
    mkdirSync(out, { recursive: true });
    await page.addInitScript(name => localStorage.setItem('theme', name), theme);
    await page.emulateMedia({ colorScheme: theme });
    await page.setViewportSize({ width: 1280, height: 800 });
    const results: PageResult[] = [];
    const errors: string[] = [];
    const routeDir = path.join(out, `routes-${theme}`);
    mkdirSync(routeDir, { recursive: true });
    for (const route of await routes(page)) {
        const routeResults: PageResult[] = [];
        const routeErrors: string[] = [];
        for (const width of sweepWidths) {
            await page.setViewportSize({ width, height: width < 768 ? 812 : 800 });
            try {
                await page.goto(route.url, { waitUntil: 'networkidle' });
                if (route.prepare) await route.prepare(page);
                routeResults.push(await measure(page, route, width, theme));
            } catch (error) {
                routeErrors.push(`${route.name} at ${width}px: ${(error as Error).message.split('\n')[0]}`);
            }
        }
        results.push(...routeResults);
        errors.push(...routeErrors);
        const file = route.name.replace(/[^a-zA-Z0-9_-]/g, '-');
        writeFileSync(path.join(routeDir, `${file}.json`), JSON.stringify({ route: route.name, theme, errors: routeErrors, results: routeResults }, null, 2));
    }
    const findings = results.flatMap(result => result.findings.map(finding => ({ page: result.name, theme, ...finding })));
    const counts: Record<string, number> = {};
    for (const finding of findings) counts[finding.type] = (counts[finding.type] || 0) + 1;
    // Repeated rows collapse to one problem: same page, width, type and column.
    const distinct = new Set(findings.map(f => `${f.page}|${f.width}|${f.type}|${f.selector.replace(/tr:nth-child\(\d+\)|li:nth-child\(\d+\)/g, '')}`));
    const scrollingTables = results.filter(result => result.tables.some(overrun => overrun > 1)).map(result => `${result.name}@${result.width}`);
    const summary = { theme, widths: sweepWidths, pages: results.length, counts, total: findings.length, distinct: distinct.size, scrollingTables, errors };
    writeFileSync(path.join(out, `findings-${theme}.json`), JSON.stringify({ summary, results }, null, 2));
    console.log(JSON.stringify(summary));
    if (process.env.RESPONSIVE_AUDIT_STRICT) {
        expect(errors).toEqual([]);
        expect(findings.filter(f => f.type !== 'touch-target')).toEqual([]);
    }
}

test('responsive sizing sweep, light theme', async ({ page }) => {
    test.setTimeout(30 * 60_000);
    await sweep(page, 'light', widths);
});

test('responsive sizing spot check, dark theme at 1280px', async ({ page }) => {
    test.setTimeout(10 * 60_000);
    await sweep(page, 'dark', [1280]);
});
