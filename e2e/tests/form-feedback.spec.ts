import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const paths = ['/orders/create', '/purchase-orders/create', '/stock-transfers/create', '/stock-audits/create'];

async function addProduct(page: Page, selector: string) {
    await page.locator(selector).fill('E2E-LAYOUT-HUB');
    await page.locator('.product-picker-popup').getByRole('option').click();
    if (!selector.includes('repeat-1')) await page.getByRole('button', { name: 'Add', exact: true }).click();
}

async function prepareInvalid(page: Page, path: string) {
    await page.goto(path, { waitUntil: 'networkidle' });
    if (path === '/orders/create') {
        await page.locator('#customer_name').fill('C'.repeat(256));
        await page.locator('#notes').fill('Retain these order notes');
        await addProduct(page, '#add_product');
        return page.locator('#customer_name');
    }
    if (path === '/purchase-orders/create') {
        await page.locator('#supplier_id').selectOption({ index: 1 });
        await page.locator('#expected_date').fill('1990-01-01');
        await page.locator('#notes').fill('Retain these purchase notes');
        await addProduct(page, '#product');
        return page.locator('#expected_date');
    }
    if (path === '/stock-transfers/create') {
        const from = page.getByLabel('From Location');
        await from.selectOption({ index: 1 });
        await page.getByLabel('To Location').selectOption({ index: 1 });
        await addProduct(page, '[id$="-repeat-1-0"]');
        const notes = page.locator('textarea[id$="-3"]');
        await notes.fill('T'.repeat(1001));
        return notes;
    }
    await page.locator('input[id$="-1"]').fill('Retain audit name');
    const description = page.locator('textarea[id$="-2"]');
    // Exercise the server fallback past the browser's existing maxlength.
    await description.evaluate(element => element.removeAttribute('maxlength'));
    await description.fill('D'.repeat(1001));
    return description;
}

for (const width of [320, 375, 768, 1440]) {
    test(`shared actions remain reachable on long forms at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        for (const path of paths) {
            await page.goto(path, { waitUntil: 'networkidle' });
            const actions = page.locator('.form-actions');
            await expect(actions).toBeInViewport();
            const button = actions.getByRole('button');
            expect((await button.boundingBox())!.height).toBeGreaterThanOrEqual(width < 640 ? 44 : 40);
            await page.evaluate(() => scrollTo(0, 400));
            await expect(actions).toBeInViewport();
            await page.locator('form').last().locator('input, textarea, select').first().focus();
            const field = await page.locator('form').last().locator('input, textarea, select').first().boundingBox();
            const bar = await actions.boundingBox();
            expect(field!.y).toBeGreaterThanOrEqual(0);
            expect(field!.y + field!.height).toBeLessThanOrEqual(bar!.y);
            expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
        }
    });
}

for (const path of paths) {
    test(`${path} focuses server errors, links to fields, and keeps the entered data`, async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 900 });
        const invalid = await prepareInvalid(page, path);
        const before = await invalid.inputValue();
        const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === path.replace('/create', ''));
        await page.locator('.form-actions').getByRole('button').click();
        expect([302, 303]).toContain((await response).status());
        const summary = page.getByRole('region', { name: 'Check these fields', exact: true });
        await expect(summary).toBeFocused();
        await expect(summary).toBeInViewport();
        await expect(invalid).toHaveValue(before);
        await expect(invalid).toHaveAttribute('aria-invalid', 'true');
        const descriptionId = await invalid.getAttribute('aria-describedby');
        await expect(page.locator(`[id="${descriptionId}"]`)).toBeVisible();
        await summary.getByRole('link').first().click();
        await expect(invalid).toBeFocused();
        const field = await invalid.boundingBox();
        const bar = await page.locator('.form-actions').boundingBox();
        expect(field!.y).toBeGreaterThanOrEqual(48);
        expect(field!.y + field!.height).toBeLessThanOrEqual(bar!.y);
        await expect(page).toHaveURL(new RegExp(path + '$'));
        if (path.includes('orders')) await expect(page.locator('#notes')).toHaveValue(/Retain these/);
    });
}

test('a pending save blocks duplicate form submission and unlocks after validation', async ({ page }) => {
    await prepareInvalid(page, '/purchase-orders/create');
    let release!: () => void;
    const held = new Promise<void>(resolve => { release = resolve; });
    let posts = 0;
    await page.route('**/purchase-orders', async route => {
        if (route.request().method() !== 'POST') return route.continue();
        posts++;
        await held;
        await route.continue();
    });
    const actions = page.locator('.form-actions');
    await actions.getByRole('button').click();
    await expect(actions).toHaveAttribute('aria-busy', 'true');
    await expect(actions.getByRole('button')).toBeDisabled();
    await page.locator('form').filter({ has: page.locator('#supplier_id') }).evaluate((form: HTMLFormElement) => form.requestSubmit());
    expect(posts).toBe(1);
    release();
    await expect(page.locator('.form-error-summary')).toBeFocused();
    await expect(actions.getByRole('button')).toBeEnabled();
    expect(posts).toBe(1);
});

test('error recovery is readable in dark mode, RTL and forced colors', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 900 });
    await page.addInitScript(() => localStorage.setItem('theme', 'dark'));
    await page.route('**/stock-audits/create', async route => {
        const response = await route.fetch();
        const body = await response.text();
        if (route.request().headers()['x-inertia']) {
            const data = JSON.parse(body);
            data.props.locale = 'ar';
            data.props.errors.workflow = 'Production policy requires a review.';
            return route.fulfill({ response, json: data });
        }
        const match = body.match(/(<script\b[^>]*\bdata-page="app"[^>]*>)([\s\S]*?)(<\/script>)/)!;
        const data = JSON.parse(match[2]);
        data.props.locale = 'ar';
        await route.fulfill({ response, body: body.replace(match[0], match[1] + JSON.stringify(data).replaceAll('<', '\\u003c') + match[3]) });
    });
    await page.route('**/stock-audits', async route => {
        const response = await route.fetch();
        const data = await response.json();
        data.props.locale = 'ar';
        data.props.errors.workflow = 'Production policy requires a review.';
        await route.fulfill({ response, json: data });
    });
    await prepareInvalid(page, '/stock-audits/create');
    await page.locator('.form-actions').getByRole('button').click();
    const summary = page.locator('.form-error-summary');
    await expect(summary).toBeFocused();
    await expect(summary.getByRole('heading')).toHaveText('تحقق من هذه الحقول');
    await expect(summary.getByText('Production policy requires a review.', { exact: true })).toBeVisible();
    await expect(summary.getByRole('link', { name: 'Production policy requires a review.' })).toHaveCount(0);
    expect((await new AxeBuilder({ page }).include('.form-error-summary').include('.form-actions').analyze()).violations).toEqual([]);
    await page.emulateMedia({ forcedColors: 'active' });
    await summary.getByRole('link').first().click();
    await expect(page.locator('textarea[id$="-2"]')).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
});
