import { chromium } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const stage = process.argv[2];
if (!['before', 'after'].includes(stage)) throw new Error('Choose before or after.');
const directory = fileURLToPath(new URL(`./${stage}/`, import.meta.url));
mkdirSync(directory, { recursive: true });
const browser = await chromium.launch();
const evidence = [];
try {
    for (const [name, width, height, theme, sku] of [
        ['supplier-desktop', 1440, 1000, 'light', 'SKU-10014'],
        ['supplier-phone', 375, 812, 'light', 'SKU-10014'],
        ['supplier-dark', 1440, 1000, 'dark', 'SKU-10014'],
        ['no-supplier', 1440, 1000, 'light', 'SKU-10001'],
        ['no-suggestion', 1440, 1000, 'light', 'SKU-10010'],
    ]) {
        const context = await browser.newContext({ storageState: 'e2e/.auth/dashboard-design.json', viewport: { width, height } });
        await context.addInitScript(theme => localStorage.setItem('theme', theme), theme);
        const page = await context.newPage();
        await page.goto(`${process.env.CAPTURE_BASE_URL || 'http://127.0.0.1:8225'}/dashboard`, { waitUntil: 'networkidle' });
        await page.locator('.dashboard-stock-row').filter({ hasText: sku }).getByRole('button', { name: 'Review stock', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await dialog.waitFor();
        await page.evaluate(() => document.fonts.ready);
        await page.screenshot({ path: `${directory}/${name}.png` });
        evidence.push({ name, width, height, theme, sku, text: await dialog.innerText(), overflow: await page.evaluate(() => document.documentElement.scrollWidth - innerWidth) });
        await context.close();
    }
    writeFileSync(`${directory}/evidence.json`, `${JSON.stringify(evidence, null, 2)}\n`);
    console.log(evidence.map(({ name, overflow }) => ({ name, overflow })));
} finally {
    await browser.close();
}
