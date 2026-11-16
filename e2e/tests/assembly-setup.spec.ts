import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const component = { key: 0, product_id: 990002, name: 'Shelf bracket', sku: 'BRACKET-1', stock: 0, tracking_type: 'none', quantity: 2, kit: null, variants: [] };
const assembly = { id: 990001, name: 'Adjustable shelving assembly', sku: 'SHELF-ASSEMBLY', tracking_type: 'none', variants: [], components: [component] };

async function render(page: Page, products: object[], permissions?: string[], locale = 'en') {
    await page.route('**/work-orders/create*', async route => {
        const response = await route.fetch();
        const body = await response.text();
        const match = body.match(/(<script\b[^>]*\bdata-page="app"[^>]*>)([\s\S]*?)(<\/script>)/)!;
        const data = JSON.parse(match[2]);
        data.props.assemblyProducts = products;
        data.props.preselectedProductId = null;
        data.props.locale = locale;
        if (permissions) data.props.auth.permissions = permissions;
        await route.fulfill({ response, body: body.replace(match[0], match[1] + JSON.stringify(data).replaceAll('<', '\\u003c') + match[3]) });
    }, { times: 1 });
    await page.goto('/work-orders/create', { waitUntil: 'networkidle' });
}

for (const width of [320, 375, 768, 1440]) {
    test(`empty assembly catalog gives a usable setup path at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await render(page, []);
        await expect(page.getByRole('heading', { name: 'Set up an assembly first' })).toBeVisible();
        await expect(page.getByRole('combobox', { name: 'Assembly Product' })).toHaveCount(0);
        const setup = page.getByRole('link', { name: 'Create assembly product', exact: true });
        await expect(setup).toBeInViewport();
        expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
        await setup.click();
        await expect(page.locator('#type')).toHaveValue('assembly');
        await expect(page.getByText('After saving, open the product and add components in Bill of Materials.')).toBeVisible();
        await page.locator('#type').selectOption('standard');
        await expect(page.getByText('After saving, open the product and add components in Bill of Materials.')).toHaveCount(0);
    });
}

test('operators without product access get an honest setup explanation', async ({ page }) => {
    await render(page, [], ['manage_stock']);
    await expect(page.getByText('Ask someone who manages products to set up an assembly and its components.')).toBeVisible();
    await expect(page.locator('.empty-state a')).toHaveCount(0);
});

test('missing components lead to repair without a doomed submission', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 900 });
    await render(page, [{ ...assembly, components: [] }]);
    const submit = page.getByRole('button', { name: 'Create Work Order', exact: true });
    await expect(submit).toBeDisabled();
    await page.locator('#product_id').selectOption(String(assembly.id));
    await expect(page.getByRole('link', { name: 'Review bill of materials', exact: true })).toHaveAttribute('href', /products\/990001#product-components$/);
    await expect(submit).toBeDisabled();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
});

test('a stock shortage does not prevent creating a valid draft and variants stay required', async ({ page }) => {
    await render(page, [assembly, { ...assembly, id: 990003, variants: [{ id: 990004, title: 'Blue', sku: 'BLUE', stock: 0 }] }]);
    await page.locator('#product_id').selectOption(String(assembly.id));
    await expect(page.getByRole('button', { name: 'Create Work Order', exact: true })).toBeEnabled();
    await expect(page.getByText('Insufficient', { exact: true })).toBeVisible();
    await page.locator('#product_id').selectOption('990003');
    await expect(page.locator('#product_variant_id')).toHaveAttribute('required', '');
    expect(await page.locator('#product_variant_id').evaluate((element: HTMLSelectElement) => element.checkValidity())).toBe(false);
    await page.locator('#product_variant_id').selectOption('990004');
    expect(await page.locator('#product_variant_id').evaluate((element: HTMLSelectElement) => element.checkValidity())).toBe(true);
});

test('setup guidance supports dark mode, RTL, and assistive technology', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 900 });
    await page.addInitScript(() => localStorage.setItem('theme', 'dark'));
    await render(page, [], undefined, 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    expect((await new AxeBuilder({ page }).include('.empty-state').analyze()).violations).toEqual([]);
    await page.emulateMedia({ forcedColors: 'active' });
    const setup = page.locator('.empty-state a');
    await setup.focus();
    await expect(setup).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBe(0);
});
