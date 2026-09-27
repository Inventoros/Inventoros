import { execSync } from 'child_process';
import { test, expect } from '../fixtures';
import type { Page } from '@playwright/test';

/**
 * Runtime plugin UI under the production Content Security Policy.
 *
 * Activates the bundled hello-world plugin, whose UI ships as a pre-built ES
 * module in plugins/hello-world/dist. Nothing about it is compiled into the
 * app's Vite build, so everything asserted here arrives through a runtime
 * import() of /plugins/hello-world/plugin.js.
 */

const artisan = (command: string) => execSync(`php artisan ${command}`, { cwd: process.cwd(), stdio: 'pipe' });

/** Record CSP violations and console errors for the page's lifetime. */
async function watchForProblems(page: Page): Promise<string[]> {
    const problems: string[] = [];

    page.on('console', (message) => {
        if (message.type() === 'error') {
            problems.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));

    await page.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (event) => {
            console.error(`CSP violation: ${event.violatedDirective} ${event.blockedURI}`);
        });
    });

    return problems;
}

test.describe('Runtime plugin UI', () => {
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(() => {
        artisan('plugin:activate hello-world');
    });

    test.afterAll(() => {
        artisan('plugin:deactivate hello-world');
    });

    test('the Content Security Policy allows same-origin module imports', async ({ page }) => {
        const response = await page.goto('/dashboard');
        const csp = response?.headers()['content-security-policy'] ?? '';

        expect(csp).toMatch(/script-src 'self' 'nonce-[^']+'/);
        expect(csp).not.toContain('strict-dynamic');
    });

    test('dashboard renders the plugin banner and widget', async ({ page }) => {
        const problems = await watchForProblems(page);

        await page.goto('/dashboard');

        await expect(page.getByText('Hello from the Hello World plugin')).toBeVisible();
        await expect(page.getByText('products say hello')).toBeVisible();
        expect(problems).toEqual([]);
    });

    test('a registered plugin page renders on a full page load', async ({ page }) => {
        const problems = await watchForProblems(page);

        await page.goto('/hello-world');

        await expect(page.getByRole('heading', { level: 1, name: 'Hello World' })).toBeVisible();
        await expect(page.getByText(/registered with plugin\.registerPage\(\)/)).toBeVisible();
        expect(problems).toEqual([]);
    });

    test('a plugin tab appears on the product page next to the core overview', async ({ page }) => {
        const problems = await watchForProblems(page);

        // Seeded by E2ETestSeeder (TEST_PRODUCT_SKU).
        await page.goto('/products?search=E2E-PRODUCT-1');
        await page.getByRole('link', { name: 'E2E Test Product' }).first().click();
        await expect(page).toHaveURL(/\/products\/\d+$/);

        const overview = page.getByRole('tab', { name: 'Overview' });
        const hello = page.getByRole('tab', { name: 'Hello' });
        await expect(overview).toHaveAttribute('aria-selected', 'true');

        await hello.click();
        await expect(hello).toHaveAttribute('aria-selected', 'true');
        await expect(page.getByText('Hello, E2E-PRODUCT-1')).toBeVisible();

        await overview.click();
        await expect(page.getByText('Hello, E2E-PRODUCT-1')).toBeHidden();
        expect(problems).toEqual([]);
    });

    test('a registered plugin page renders after client-side navigation', async ({ page }) => {
        const problems = await watchForProblems(page);

        await page.goto('/dashboard');
        await page.getByRole('link', { name: 'Open the Hello World page' }).click();

        await expect(page).toHaveURL(/\/hello-world$/);
        await expect(page.getByRole('heading', { level: 1, name: 'Hello World' })).toBeVisible();

        await page.getByRole('link', { name: 'Back to the dashboard' }).click();
        await expect(page).toHaveURL(/\/dashboard$/);
        expect(problems).toEqual([]);
    });
});
