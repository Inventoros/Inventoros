import { test, expect } from '@playwright/test';
import { fileURLToPath } from 'url';
import path from 'path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const screenshotDir = path.join(__dirname, '../../screenshots');

const pages = [
    { name: 'dashboard', path: '/dashboard', waitFor: 'h1' },
    { name: 'products', path: '/products', waitFor: 'table' },
    { name: 'orders', path: '/orders', waitFor: 'table' },
    { name: 'customers', path: '/customers', waitFor: 'table' },
    { name: 'locations', path: '/locations', waitFor: 'main' },
    { name: 'purchase-orders', path: '/purchase-orders', waitFor: 'table' },
    { name: 'reports', path: '/reports', waitFor: 'main' },
    { name: 'categories', path: '/categories', waitFor: 'main' },
    { name: 'suppliers', path: '/suppliers', waitFor: 'table' },
    { name: 'settings', path: '/settings/organization', waitFor: 'form' },
];

const themes = [
    { name: 'dark', suffix: '' },
    { name: 'light', suffix: '-light' },
] as const;

for (const theme of themes) {
    for (const pg of pages) {
        test(`screenshot ${theme.name}: ${pg.name}`, async ({ page }) => {
            await page.emulateMedia({ colorScheme: theme.name });
            // Set the preference before the app mounts so the theme toggle,
            // charts and page all initialize in the theme being captured.
            await page.addInitScript((name) => localStorage.setItem('theme', name), theme.name);
            await page.goto(pg.path, { waitUntil: 'networkidle' });
            await expect(page.locator('h1')).toBeVisible();
            await page.locator(pg.waitFor).first().waitFor({ state: 'visible' });
            if (theme.name === 'dark') await expect(page.locator('html')).toHaveClass(/dark/);
            else await expect(page.locator('html')).not.toHaveClass(/dark/);
            await page.evaluate(() => document.fonts.ready);
            // Let animations settle
            await page.waitForTimeout(500);
            await page.screenshot({
                path: path.join(screenshotDir, `${pg.name}${theme.suffix}.png`),
                fullPage: false,
                animations: 'disabled',
                caret: 'hide',
            });
            if (pg.name === 'products') {
                await page.getByRole('button', { name: 'Full table view', exact: true }).click();
                const fullTable = page.locator('.table-full-dialog[open]');
                await expect(fullTable.locator('table')).toBeVisible();
                await expect(fullTable.locator('.table-pagination')).toBeVisible();
                await page.screenshot({
                    path: path.join(screenshotDir, `products-full${theme.suffix}.png`),
                    fullPage: false,
                    animations: 'disabled',
                    caret: 'hide',
                });
            }
        });
    }
}

// ----------------------------------------------------------------
// README set -> screenshots/readme/<name>-<mode>.png
// The README's images, in both GitHub themes. Each capture is placed in a
// browser window on a soft backdrop in the logo's blue, navy and orange, so
// the README reads like a product page rather than a raw screenshot.
// ----------------------------------------------------------------

const readmeDir = path.join(screenshotDir, 'readme');

const FRAME = {
    light: {
        page: '#f5f6f9',
        glow: ['rgba(26,114,226,0.20)', 'rgba(253,121,2,0.12)', 'rgba(3,65,142,0.16)', 'rgba(26,114,226,0.14)'],
        chrome: '#ffffff',
        border: 'rgba(15,23,42,0.10)',
        dot: '#d4d7de',
        pill: '#f1f3f7',
        pillText: '#6b7280',
        shadow: '0 30px 70px -20px rgba(15,23,42,0.28), 0 12px 24px -12px rgba(15,23,42,0.14)',
    },
    dark: {
        page: '#07080b',
        glow: ['rgba(26,114,226,0.26)', 'rgba(253,121,2,0.14)', 'rgba(3,65,142,0.30)', 'rgba(26,114,226,0.18)'],
        chrome: '#121318',
        border: 'rgba(255,255,255,0.09)',
        dot: '#2f3139',
        pill: '#1b1d24',
        pillText: '#8b8f9a',
        shadow: '0 30px 80px -20px rgba(0,0,0,0.75), 0 0 0 1px rgba(255,255,255,0.04)',
    },
} as const;

function frameHtml(png: Buffer, mode: 'light' | 'dark', url: string): string {
    const c = FRAME[mode];
    const [g1, g2, g3, g4] = c.glow;
    return `<!doctype html><html><head><style>
        html, body { margin: 0; }
        body { font-family: Figtree, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        #stage {
            display: inline-block; padding: 72px 88px 88px;
            background:
                radial-gradient(60% 55% at 12% 8%, ${g1}, transparent 70%),
                radial-gradient(50% 50% at 38% 100%, ${g2}, transparent 70%),
                radial-gradient(55% 55% at 88% 12%, ${g3}, transparent 70%),
                radial-gradient(50% 60% at 100% 95%, ${g4}, transparent 70%),
                ${c.page};
        }
        .window { width: 1440px; border-radius: 14px; overflow: hidden; background: ${c.chrome};
            border: 1px solid ${c.border}; box-shadow: ${c.shadow}; }
        .bar { height: 44px; display: flex; align-items: center; gap: 8px; padding: 0 16px; border-bottom: 1px solid ${c.border}; }
        .dot { width: 12px; height: 12px; border-radius: 50%; background: ${c.dot}; }
        .pill { margin: 0 auto; transform: translateX(-30px); height: 26px; min-width: 380px; padding: 0 14px; border-radius: 7px;
            background: ${c.pill}; color: ${c.pillText}; font-size: 13px; display: flex; align-items: center; justify-content: center; }
        img { display: block; width: 1440px; }
    </style></head><body><div id="stage"><div class="window">
        <div class="bar"><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="pill">${url}</span></div>
        <img src="data:image/png;base64,${png.toString('base64')}">
    </div></div></body></html>`;
}

const readmeShots = [
    { name: 'dashboard', path: '/dashboard', waitFor: 'h1' },
    { name: 'products', path: '/products', waitFor: 'table' },
    { name: 'reports', path: '/reports', waitFor: 'main' },
];

test.describe('README set', () => {
    test.use({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });

    for (const shot of readmeShots) {
        for (const mode of ['dark', 'light'] as const) {
            test(`readme ${shot.name}-${mode}`, async ({ page }) => {
                await page.emulateMedia({ colorScheme: mode });
                await page.addInitScript((name) => localStorage.setItem('theme', name), mode);
                await page.goto(shot.path, { waitUntil: 'networkidle' });
                await expect(page.locator('h1')).toBeVisible();
                await page.locator(shot.waitFor).first().waitFor({ state: 'visible' });
                if (mode === 'dark') await expect(page.locator('html')).toHaveClass(/dark/);
                else await expect(page.locator('html')).not.toHaveClass(/dark/);
                await page.evaluate(() => document.fonts.ready);
                // Product photos load lazily: wait (briefly) for the ones on screen.
                // Images in collapsed or off-screen sections never load, so they are skipped.
                await page.evaluate(() => {
                    const onScreen = [...document.images].filter((img) => {
                        const r = img.getBoundingClientRect();
                        return img.offsetParent !== null && r.bottom > 0 && r.top < window.innerHeight && !img.complete;
                    });
                    const decoded = Promise.all(onScreen.map((img) => img.decode().catch(() => undefined)));
                    return Promise.race([decoded, new Promise((resolve) => setTimeout(resolve, 5000))]);
                });
                if (shot.name === 'products') {
                    // Every on-screen product photo must have loaded: a broken
                    // or still-pending thumbnail would ship in the README.
                    const thumbs = await page.evaluate(() => [...document.querySelectorAll<HTMLImageElement>('.product-thumb img')]
                        .filter((img) => {
                            const r = img.getBoundingClientRect();
                            return img.offsetParent !== null && r.bottom > 0 && r.top < window.innerHeight;
                        })
                        .map((img) => ({ src: img.currentSrc || img.src, complete: img.complete, width: img.naturalWidth })));
                    expect(thumbs.length).toBeGreaterThan(0);
                    for (const thumb of thumbs) {
                        expect(thumb, thumb.src).toMatchObject({ complete: true });
                        expect(thumb.width, thumb.src).toBeGreaterThan(0);
                    }
                }
                await page.waitForTimeout(300);
                const options = { animations: 'disabled' as const, caret: 'hide' as const };
                const png = await page.screenshot(options);

                await page.setViewportSize({ width: 1800, height: 1200 });
                await page.setContent(frameHtml(png, mode, `your-store.com${shot.path}`));
                await page.locator('#stage img').evaluate((img: HTMLImageElement) => img.decode());
                await page.locator('#stage').screenshot({ path: path.join(readmeDir, `${shot.name}-${mode}.png`), ...options });
            });
        }
    }
});
