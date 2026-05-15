// Quick standalone screenshot grab for the UI redesign preview pages.
// Run with: APP_URL=http://localhost:8484 node e2e/preview-screenshots.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const APP_URL = process.env.APP_URL || 'http://localhost:8484';
const EMAIL = 'e2e-test@inventoros.test';
const PASSWORD = 'E2ETestPassword123!';

const OUT = resolve('docs/ui-preview');
mkdirSync(OUT, { recursive: true });

const themes = [
    {
        name: 'light',
        script: () => {
            localStorage.setItem('theme', 'light');
            document.documentElement.classList.remove('dark');
        },
    },
    {
        name: 'dark',
        script: () => {
            localStorage.setItem('theme', 'dark');
            document.documentElement.classList.add('dark');
        },
    },
];

const pages = [
    { name: 'dashboard', path: '/preview/dashboard' },
    { name: 'products', path: '/preview/products' },
];

const browser = await chromium.launch();
const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 2,
});
const page = await context.newPage();

console.log(`→ Logging in as ${EMAIL}…`);
await page.goto(`${APP_URL}/login`, { waitUntil: 'networkidle' });
await page.waitForSelector('#email');
await page.fill('#email', EMAIL);
await page.fill('#password', PASSWORD);
await page.getByRole('button', { name: /Log in/i }).click();
await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 15000 });
console.log(`  → landed at ${page.url()}`);

for (const theme of themes) {
    console.log(`→ Theme: ${theme.name}`);
    for (const p of pages) {
        const url = `${APP_URL}${p.path}`;
        console.log(`  • ${p.name}: ${url}`);
        await page.evaluate(theme.script);   // set localStorage before nav
        await page.goto(url, { waitUntil: 'networkidle' });
        await page.evaluate(theme.script);   // reapply after nav (theme toggle re-reads on mount)
        await page.waitForTimeout(500);
        const diag = await page.evaluate(() => ({
            htmlClass: document.documentElement.className,
            bodyBg: getComputedStyle(document.body).backgroundColor,
            cssVarSurface: getComputedStyle(document.documentElement).getPropertyValue('--surface-canvas'),
        }));
        console.log(`    diag: ${JSON.stringify(diag)}`);
        const file = `${OUT}/${p.name}-${theme.name}.png`;
        await page.screenshot({ path: file, fullPage: true });
        console.log(`    ✓ ${file}`);
    }
}

await browser.close();
console.log('\n✓ Done. Screenshots in docs/ui-preview/');
