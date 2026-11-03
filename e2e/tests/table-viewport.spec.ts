import { test, expect, type Locator, type Page } from '@playwright/test';

async function expectBottomLegalFooter(page: Page, footer: Locator, height: number) {
    await expect(footer).toBeVisible();
    await expect(footer.locator('a[href="https://inventoros.com"]')).toHaveCount(1);
    await expect(footer.locator('a[href="https://www.gnu.org/licenses/agpl-3.0.html"]')).toHaveCount(1);
    await expect(footer.locator('a[href="https://github.com/Inventoros/Inventoros"]')).toHaveCount(1);
    const layout = await footer.evaluate(element => {
        const bounds = element.getBoundingClientRect();
        const content = element.querySelector('p')!;
        const centers = [...content.children].map(child => {
            const rect = child.getBoundingClientRect();
            return rect.y + rect.height / 2;
        });
        return {
            bottom: bounds.bottom,
            height: bounds.height,
            lineSpread: Math.max(...centers) - Math.min(...centers),
            fontSize: parseFloat(getComputedStyle(element).fontSize),
            inTableScroller: !!element.closest('.table-viewport'),
            dialogOverflow: element.closest('dialog')
                ? element.closest('dialog')!.scrollHeight - element.closest('dialog')!.clientHeight
                : null,
        };
    });
    expect(Math.abs(layout.bottom - height)).toBeLessThanOrEqual(1);
    expect(layout.height).toBeLessThanOrEqual(48);
    expect(layout.lineSpread).toBeLessThanOrEqual(1);
    expect(layout.fontSize).toBeGreaterThanOrEqual(12);
    expect(layout.inTableScroller).toBe(false);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
    if (layout.dialogOverflow !== null) {
        expect(layout.dialogOverflow).toBeLessThanOrEqual(0);
        expect(await page.evaluate(() => document.body.style.overflow)).toBe('hidden');
    } else {
        expect(await page.evaluate(() => document.documentElement.scrollHeight - innerHeight)).toBeLessThanOrEqual(0);
    }
}

async function expectExpandedTableFillsDialog(dialog: Locator) {
    const dimensions = await dialog.evaluate(element => {
        const panel = element.querySelector('.table-panel')!;
        const viewport = panel.querySelector('.table-viewport')!;
        const controlsHeight = [...panel.children]
            .filter(child => child !== viewport)
            .reduce((height, child) => height + child.getBoundingClientRect().height, 0);
        return {
            height: element.clientHeight,
            rowsHeight: viewport.clientHeight,
            availableRowsHeight: element.clientHeight - controlsHeight,
        };
    });
    // Fixed headings, pagination and the legal row reserve their own space;
    // the data viewport fills every remaining pixel without a body scroller.
    expect(Math.abs(dimensions.rowsHeight - dimensions.availableRowsHeight)).toBeLessThanOrEqual(1);
    expect(dimensions.rowsHeight).toBeGreaterThan(dimensions.height * 0.8);
}

for (const [name, url, height] of [
    ['inventory', '/products?per_page=100', 900],
    ['orders', '/orders', 600],
] as const) {
    test(`${name}: rows scroll without moving the page, controls or column headers`, async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1440, height });
        await page.goto(url);
        const region = page.locator('.table-viewport');
        const heading = page.locator('h1');
        const header = region.locator('thead th').first();
        const pagination = page.locator('.table-pagination');
        await expect(region).toBeVisible();
        await expect(pagination).toBeVisible();
        const before = {
            heading: (await heading.boundingBox())!.y,
            header: (await header.boundingBox())!.y,
            pagination: (await pagination.boundingBox())!.y,
        };
        const dimensions = await region.evaluate((el) => ({
            visible: el.clientHeight,
            total: el.scrollHeight,
        }));
        // Guard the original complaint: a working scroller is insufficient
        // when page chrome leaves only a small fraction of the workspace.
        expect(dimensions.visible).toBeGreaterThan(
            height * (name === 'inventory' ? 0.7 : 0.6),
        );
        expect(dimensions.total).toBeGreaterThan(dimensions.visible);
        await region.press('End');
        await expect
            .poll(() => region.evaluate((el) => el.scrollTop))
            .toBeGreaterThan(0);
        expect(
            Math.abs((await heading.boundingBox())!.y - before.heading),
        ).toBeLessThan(2);
        expect(
            Math.abs((await header.boundingBox())!.y - before.header),
        ).toBeLessThan(2);
        expect(
            Math.abs((await pagination.boundingBox())!.y - before.pagination),
        ).toBeLessThan(2);
        expect(await page.evaluate(() => window.scrollY)).toBe(0);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollHeight - innerHeight,
            ),
        ).toBeLessThanOrEqual(0);
    });
}

test('phone filters leave room for the table and close after applying a search', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/products?per_page=100');
    const region = page.locator('.table-viewport');
    const filters = page.locator('.table-filters');
    await expect(region).toBeVisible();
    await expect(filters).not.toHaveAttribute('open');
    const height = await region.evaluate((el) => el.clientHeight);
    expect(height).toBeGreaterThan(812 * 0.65);
    await page.getByRole('button', { name: 'Actions', exact: true }).click();
    await expect(
        page.getByRole('link', { name: 'Add Product', exact: true }),
    ).toBeVisible();
    expect(
        Math.abs((await region.evaluate((el) => el.clientHeight)) - height),
    ).toBeLessThan(2);
    await page.getByRole('button', { name: 'Actions', exact: true }).click();
    await filters.locator('summary').click();
    await expect(page.locator('#search')).toBeVisible();
    expect(
        Math.abs((await region.evaluate((el) => el.clientHeight)) - height),
    ).toBeLessThan(2);
    await page.locator('#search').fill('Laptop Stand Aluminum');
    await filters.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(region).toContainText('Laptop Stand Aluminum');
    await expect(filters).not.toHaveAttribute('open');
    await expect(page).toHaveURL(/search=Laptop/);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth - innerWidth,
        ),
    ).toBeLessThanOrEqual(0);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollHeight - innerHeight,
        ),
    ).toBeLessThanOrEqual(0);
});

test('full table view keeps selection, fills the window and restores keyboard focus', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/products?per_page=100');
    const first = page.locator('tbody input[type="checkbox"]').first();
    await first.check();
    const expand = page.getByRole('button', {
        name: 'Full table view',
        exact: true,
    });
    await expand.click();
    const dialog = page.locator('dialog[open]');
    await expect(dialog.locator('table')).toHaveCount(1);
    await expect(
        dialog.locator('tbody input[type="checkbox"]').first(),
    ).toBeChecked();
    await expectExpandedTableFillsDialog(dialog);
    expect(await dialog.evaluate((el) => el.clientWidth)).toBe(1440);
    await expect(dialog.locator('.table-pagination')).toBeVisible();
    await expect(
        dialog.getByRole('button', { name: 'Collapse', exact: true }),
    ).toBeFocused();
    await dialog.press('Escape');
    await expect(page.locator('dialog[open]')).toHaveCount(0);
    await expect(first).toBeChecked();
    await expect(expand).toBeFocused();
    expect(await page.evaluate(() => document.body.style.overflow)).toBe('');
});

test('embedded report tables use the same full window view', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/reports/low-stock');
    const expand = page.getByRole('button', {
        name: 'Full table view',
        exact: true,
    });
    await expand.click();
    const dialog = page.locator('dialog[open]');
    await expect(dialog.locator('table')).toBeVisible();
    await expectExpandedTableFillsDialog(dialog);
    await dialog.getByRole('button', { name: 'Collapse', exact: true }).click();
    await expect(page.locator('dialog[open]')).toHaveCount(0);
    await expect(expand).toBeFocused();
});

for (const width of [320, 1440]) {
    test(`table legal footer stays on one line under pagination at ${width}px`, async ({ page }) => {
        const height = width === 320 ? 568 : 900;
        await page.setViewportSize({ width, height });
        await page.goto('/products?per_page=100');
        const footer = page.locator('.app-main-column > .table-legal-footer');
        const pagination = page.locator('.table-pagination');
        const region = page.locator('.table-viewport');
        await expectBottomLegalFooter(page, footer, height);
        await expect(page.locator('aside footer')).toHaveCount(0);
        expect((await pagination.boundingBox())!.y + (await pagination.boundingBox())!.height)
            .toBeLessThanOrEqual((await footer.boundingBox())!.y + 1);
        const before = (await footer.boundingBox())!;
        await region.press('End');
        await expect.poll(() => region.evaluate(element => element.scrollTop)).toBeGreaterThan(0);
        expect((await footer.boundingBox())!.y).toBe(before.y);
        expect(await page.evaluate(() => scrollY)).toBe(0);

        if (width === 320) {
            expect(await footer.evaluate(element => element.scrollWidth > element.clientWidth)).toBe(true);
            await footer.locator('a[href="https://github.com/Inventoros/Inventoros"]').focus();
            await expect.poll(() => footer.evaluate(element => Math.abs(element.scrollLeft))).toBeGreaterThan(0);
            expect(await page.evaluate(() => scrollX)).toBe(0);
        }

        await page.getByRole('button', { name: 'Full table view', exact: true }).click();
        const dialog = page.locator('dialog[open]');
        const expandedFooter = dialog.locator('.table-legal-footer');
        await expectBottomLegalFooter(page, expandedFooter, height);
        const expandedPagination = (await dialog.locator('.table-pagination').boundingBox())!;
        expect(expandedPagination.y + expandedPagination.height)
            .toBeLessThanOrEqual((await expandedFooter.boundingBox())!.y + 1);
        await dialog.press('Escape');
        await expect(page.locator('dialog[open]')).toHaveCount(0);
        await expectBottomLegalFooter(page, footer, height);
    });

    test(`expanded embedded tables put the legal footer below their scroller at ${width}px`, async ({ page }) => {
        const height = width === 320 ? 568 : 900;
        await page.setViewportSize({ width, height });
        await page.goto('/reports/low-stock');
        await expect(page.locator('.table-legal-footer')).toHaveCount(0);
        const normalFooter = page.locator('.app-legal-footer');
        await expect(normalFooter).not.toHaveAttribute('tabindex');
        expect(await normalFooter.locator('p').evaluate(element => getComputedStyle(element).flexWrap)).toBe('wrap');
        await page.getByRole('button', { name: 'Full table view', exact: true }).click();
        const dialog = page.locator('dialog[open]');
        const footer = dialog.locator('.table-legal-footer');
        await expectBottomLegalFooter(page, footer, height);
        const region = (await dialog.locator('.table-viewport').boundingBox())!;
        expect(region.y + region.height).toBeLessThanOrEqual((await footer.boundingBox())!.y + 1);
        await dialog.press('Escape');
        await expect(page.locator('.table-legal-footer')).toHaveCount(0);
        await expect(normalFooter).toBeAttached();
    });
}
