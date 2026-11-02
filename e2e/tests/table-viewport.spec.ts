import { test, expect } from '@playwright/test';

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
    expect(
        await dialog
            .locator('.table-viewport')
            .evaluate((el) => el.clientHeight),
    ).toBeGreaterThan(780);
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
    expect(
        await dialog
            .locator('.table-viewport')
            .evaluate((el) => el.clientHeight),
    ).toBeGreaterThan(810);
    await dialog.getByRole('button', { name: 'Collapse', exact: true }).click();
    await expect(page.locator('dialog[open]')).toHaveCount(0);
    await expect(expand).toBeFocused();
});
