import { chromium } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const stage = process.argv[2];
if (!['before', 'after'].includes(stage)) throw new Error('Choose before or after.');
const directory = fileURLToPath(new URL(`./${stage}/`, import.meta.url));
const baseURL = process.env.CAPTURE_BASE_URL || 'http://127.0.0.1:8225';
mkdirSync(directory, { recursive: true });
const browser = await chromium.launch();
const evidence = [];

try {
    const login = await browser.newPage();
    await login.goto(`${baseURL}/login`);
    await login.locator('#email').fill('alex@northwind-supply.example');
    await login.locator('#password').fill('E2ETestPassword123!');
    await login.getByRole('button', { name: 'Log in', exact: true }).click();
    await login.waitForURL('**/dashboard');
    mkdirSync('e2e/.auth', { recursive: true });
    await login.context().storageState({ path: 'e2e/.auth/dashboard-design.json' });
    await login.close();

    for (const [variant, width, height, theme] of [
        ['desktop', 1440, 1000, 'light'], ['wide', 3440, 1440, 'light'],
        ['phone', 375, 812, 'light'], ['dark', 1440, 1000, 'dark'],
    ]) {
        const context = await browser.newContext({
            storageState: 'e2e/.auth/dashboard-design.json', viewport: { width, height },
        });
        await context.addInitScript(theme => localStorage.setItem('theme', theme), theme);
        const page = await context.newPage();
        await page.goto(`${baseURL}/dashboard`, { waitUntil: 'networkidle' });
        await page.getByRole('heading', { name: 'Dashboard', exact: true }).waitFor();
        await page.evaluate(() => document.fonts.ready);
        await page.screenshot({ path: `${directory}/dashboard-${variant}.png` });
        if (variant === 'phone') await page.screenshot({ path: `${directory}/dashboard-phone-full.png`, fullPage: true });
        evidence.push({
            variant, width, height, theme,
            ...await page.evaluate(() => ({
                overflow: document.documentElement.scrollWidth - innerWidth,
                pageHeight: document.documentElement.scrollHeight,
                metrics: [...document.querySelectorAll('.dashboard-metric')].map(e => ({
                    key: e.getAttribute('data-testid'), text: e.textContent.trim(), exact: e.title,
                })),
                stock: [...document.querySelectorAll('.dashboard-stock-row')].map(e => e.textContent.trim()),
                stockTop: document.querySelector('.dashboard-replenishment')?.getBoundingClientRect().top,
                valuationTotal: document.querySelector('[data-testid="dashboard-category-total"]')?.textContent.trim(),
            })),
        });
        await context.close();
    }
    writeFileSync(`${directory}/evidence.json`, `${JSON.stringify(evidence, null, 2)}\n`);
    console.log(evidence.map(({ variant, overflow, pageHeight, stockTop, valuationTotal }) => ({ variant, overflow, pageHeight, stockTop, valuationTotal })));
} finally {
    await browser.close();
}
