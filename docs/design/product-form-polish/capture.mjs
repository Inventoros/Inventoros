// Run against an isolated demo instance with a prepared Playwright login:
// CAPTURE_BASE_URL=http://127.0.0.1:8000 PRODUCT_FORM_EDIT_PATH=/products/2/edit node docs/design/product-form-polish/capture.mjs after
import { chromium } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const stage = process.argv[2];
if (!['before', 'after'].includes(stage)) throw new Error('Choose before or after.');
if (!process.env.CAPTURE_BASE_URL || !process.env.PRODUCT_FORM_EDIT_PATH) {
    throw new Error('Set CAPTURE_BASE_URL and PRODUCT_FORM_EDIT_PATH for the demo instance.');
}
const directory = fileURLToPath(new URL(`./${stage}/`, import.meta.url));
mkdirSync(directory, { recursive: true });
const browser = await chromium.launch();
const measurements = [];

try {
    for (const [variant, width, height, dark] of [
        ['desktop', 1440, 1000, false],
        ['mobile', 375, 812, false],
        ['dark', 1440, 1000, true],
    ]) {
        const context = await browser.newContext({
            storageState: process.env.CAPTURE_AUTH_STATE || 'e2e/.auth/user.json',
            viewport: { width, height },
        });
        await context.addInitScript(dark => localStorage.setItem('theme', dark ? 'dark' : 'light'), dark);
        const page = await context.newPage();
        for (const [name, path] of [['create', '/products/create'], ['edit', process.env.PRODUCT_FORM_EDIT_PATH]]) {
            await page.goto(new URL(path, process.env.CAPTURE_BASE_URL).href, { waitUntil: 'networkidle' });
            if (new URL(page.url()).pathname !== path) throw new Error(`Unexpected redirect: ${page.url()}`);
            await page.locator('h1').waitFor();
            await page.evaluate(() => document.fonts.ready);
            await page.screenshot({ path: `${directory}/${name}-${variant}.png` });
            measurements.push({
                name, variant,
                ...await page.evaluate(() => ({
                    pageHeight: document.documentElement.scrollHeight,
                    stockY: document.querySelector('#stock').getBoundingClientRect().y,
                    submitY: document.querySelector('form button[type=submit]').getBoundingClientRect().y,
                    overflow: document.documentElement.scrollWidth - innerWidth,
                })),
            });
        }
        await context.close();
    }
    writeFileSync(`${directory}/measurements.json`, `${JSON.stringify(measurements, null, 2)}\n`);
    console.log(measurements);
} finally {
    await browser.close();
}
