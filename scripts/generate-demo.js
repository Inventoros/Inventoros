#!/usr/bin/env node
/**
 * Records the README demo GIFs in .github/demo/ from the running app, seeded
 * with ReadmeDemoSeeder (a made-up company, Alder & Finch Supply Co.):
 *
 *   receive-stock.gif   a low-stock item on the dashboard, its purchase order
 *                       received, and the dashboard clear again
 *   order-to-ship.gif   a new customer order written up and marked shipped
 *
 * Frames come from Chromium's screencast as lossless PNGs (a recorded video is
 * lossy and blurs small text), each held for as long as it stayed on screen,
 * then ffmpeg builds one palette per clip and encodes the GIF.
 *
 * Each flow changes the data, so reseed before running it again:
 *   php artisan migrate:fresh --seed --seeder=ReadmeDemoSeeder
 *
 *   DEMO_BASE_URL     app to record (default http://127.0.0.1:8000)
 *   DEMO_ONLY         record just one flow: receive-stock or order-to-ship
 *   DEMO_DEBUG_FRAMES write a few PNG frames next to each GIF for a quick look
 *   KEEP_FRAMES       keep the raw screencast frames in recordings/
 */
import { chromium } from 'playwright';
import { execFileSync } from 'child_process';
import { mkdirSync, rmSync, statSync, writeFileSync } from 'fs';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const BASE_URL = (process.env.DEMO_BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const RECORDINGS_DIR = resolve(ROOT, 'recordings');
const OUTPUT_DIR = resolve(ROOT, '.github/demo');
const VIEWPORT = { width: 1280, height: 800 };
const GIF_WIDTH = VIEWPORT.width;
const GIF_FPS = 12;
const MAX_GIF_BYTES = 5 * 1024 * 1024;

// Must match database/seeders/ReadmeDemoSeeder.php.
const DEMO_USER = { email: 'maya@alderfinch.example', password: 'ReadmeDemoPassword123!' };
const RESTOCK_PO = 'PO-1048';

function checkDependencies() {
    try {
        execFileSync('ffmpeg', ['-version'], { stdio: 'pipe' });
    } catch {
        console.error('[fatal] ffmpeg not found. Install it before running this script.');
        console.error('  macOS:   brew install ffmpeg');
        console.error('  Ubuntu:  sudo apt-get install -y ffmpeg');
        console.error('  Windows: winget install ffmpeg');
        process.exit(1);
    }
}

const pause = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * A visible pointer. Headless Chromium draws no cursor, so the page gets an
 * arrow that follows mouse events; page.mouse.move with steps animates it.
 */
const CURSOR_SCRIPT = () => {
    const install = () => {
        if (document.getElementById('demo-cursor')) return;
        const el = document.createElement('div');
        el.id = 'demo-cursor';
        el.innerHTML =
            '<svg width="22" height="22" viewBox="0 0 24 24"><path d="M4 2l15 10.5-6.6 1.2 3.9 7.4-2.9 1.5-3.9-7.5L4 20z" fill="#fff" stroke="#111" stroke-width="1.4" stroke-linejoin="round"/></svg>';
        const start = JSON.parse(sessionStorage.getItem('demo-cursor') || '[640,400]');
        Object.assign(el.style, {
            position: 'fixed',
            left: '0',
            top: '0',
            zIndex: '2147483647',
            pointerEvents: 'none',
            transform: `translate(${start[0] - 3}px, ${start[1] - 2}px)`,
            filter: 'drop-shadow(0 1px 2px rgba(0,0,0,.5))',
        });
        document.documentElement.appendChild(el);
        document.addEventListener(
            'mousemove',
            (e) => {
                el.style.transform = `translate(${e.clientX - 3}px, ${e.clientY - 2}px)`;
                sessionStorage.setItem('demo-cursor', JSON.stringify([e.clientX, e.clientY]));
            },
            { capture: true, passive: true },
        );
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install);
    else install();
};

let mouse = { x: 640, y: 400 };

/** Glides the pointer to the middle of an element. */
async function moveTo(page, locator, { steps = 14, settle = 220 } = {}) {
    await locator.scrollIntoViewIfNeeded();
    const box = await locator.boundingBox();
    if (!box) throw new Error(`No bounding box for ${locator}`);
    const x = Math.round(box.x + box.width / 2);
    const y = Math.round(box.y + box.height / 2);
    await page.mouse.move(x, y, { steps });
    mouse = { x, y };
    await pause(settle);
}

async function clickOn(page, locator, opts = {}) {
    await moveTo(page, locator, opts);
    await locator.click();
}

async function typeSlowly(locator, text, delay = 55) {
    await locator.pressSequentially(text, { delay });
}

/** Scrolls in small wheel steps so the frames show movement, not a jump. */
async function smoothScroll(page, dy, steps = 10) {
    for (let i = 0; i < steps; i++) {
        await page.mouse.wheel(0, dy / steps);
        await pause(35);
    }
    await pause(250);
}

async function waitForPage(page, heading) {
    await page.getByRole('heading', { name: heading, level: 1, exact: true }).waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForLoadState('networkidle');
}

/** Chromium screencast: a PNG each time the page repaints, with its timestamp. */
async function startScreencast(page, framesDir) {
    const frames = [];
    const session = await page.context().newCDPSession(page);
    session.on('Page.screencastFrame', async ({ data, metadata, sessionId }) => {
        const file = resolve(framesDir, `f${String(frames.length).padStart(5, '0')}.png`);
        writeFileSync(file, Buffer.from(data, 'base64'));
        frames.push({ file, t: metadata.timestamp });
        await session.send('Page.screencastFrameAck', { sessionId }).catch(() => {});
    });
    await session.send('Page.startScreencast', {
        format: 'png',
        maxWidth: VIEWPORT.width,
        maxHeight: VIEWPORT.height,
        everyNthFrame: 1,
    });
    return {
        frames,
        async stop() {
            await session.send('Page.stopScreencast').catch(() => {});
            return Date.now() / 1000;
        },
    };
}

/** A low-stock item, the purchase order that restocks it, and a clear dashboard. */
async function receiveStockFlow(page) {
    await page.getByText('Aluminium Laptop Stand').first().waitFor({ state: 'visible' });
    await pause(1200);

    console.log('[receive-stock] Laptop stands are below minimum; opening purchase orders...');
    await moveTo(page, page.getByText('8 below minimum'), { settle: 900 });
    await clickOn(page, page.getByRole('link', { name: 'Purchase Orders', exact: true }));
    await waitForPage(page, 'Purchase Orders');
    await pause(700);

    const row = page.getByRole('row').filter({ hasText: RESTOCK_PO });
    await moveTo(page, row.getByText(RESTOCK_PO), { settle: 400 });
    await row.getByRole('link', { name: /view/i }).first().click();
    await waitForPage(page, RESTOCK_PO);
    await pause(1300);

    console.log('[receive-stock] Receiving the delivery...');
    await clickOn(page, page.getByRole('link', { name: 'Receive Items' }).first());
    await waitForPage(page, `Receive Items - ${RESTOCK_PO}`);
    await pause(700);

    const inputs = page.getByRole('spinbutton', { name: 'Receive Now' });
    const qty = ['40', '20'];
    for (let i = 0; i < qty.length; i++) {
        const input = inputs.nth(i);
        await clickOn(page, input, { settle: 120 });
        await input.press('Control+A');
        await typeSlowly(input, qty[i], 120);
        await pause(300);
    }
    await pause(500);
    await clickOn(page, page.getByRole('button', { name: 'Receive', exact: true }));
    await waitForPage(page, RESTOCK_PO);
    await pause(2200);

    console.log('[receive-stock] Back to the dashboard...');
    await clickOn(page, page.getByRole('link', { name: 'Dashboard', exact: true }));
    await waitForPage(page, 'Dashboard');
    const stocked = page.getByText('All products are well stocked');
    await moveTo(page, stocked, { steps: 18, settle: 200 });
    await page.mouse.move(mouse.x + 20, mouse.y + 34, { steps: 8 });
    await pause(2600);
}

/** A customer order written up, then marked shipped. */
async function orderToShipFlow(page) {
    await pause(1000);

    console.log('[order-to-ship] New order...');
    await clickOn(page, page.getByRole('link', { name: 'New order' }));
    await waitForPage(page, 'Create Order');
    await pause(600);

    const customer = page.locator('input[role="combobox"]').first();
    await clickOn(page, customer);
    await typeSlowly(customer, 'Lark', 90);
    const option = page.getByRole('option', { name: /Larkspur Dental Group/ });
    await option.waitFor({ state: 'visible', timeout: 5000 });
    await pause(400);
    await clickOn(page, option, { settle: 150 });
    await pause(900);

    await smoothScroll(page, 420);
    const product = page.locator('#add_product');
    const quantity = page.locator('[id$="-1"][type="number"]').first();
    const add = page.getByRole('button', { name: /^Add/ }).first();

    const lines = [
        ['Ergonomic Mesh Chair', '4'],
        ['Sit-Stand Desk Frame', '4'],
    ];
    for (const [name, qty] of lines) {
        await clickOn(page, product, { settle: 150 });
        await product.selectOption({ label: await optionLabel(product, name) });
        await pause(400);
        await clickOn(page, quantity, { settle: 100 });
        await quantity.press('Control+A');
        await typeSlowly(quantity, qty, 120);
        await pause(250);
        await clickOn(page, add, { settle: 150 });
        await pause(700);
    }

    await smoothScroll(page, 260);
    await pause(600);
    await clickOn(page, page.getByRole('button', { name: 'Create Order' }));
    // Saving lands on the order list with the new order at the top.
    await waitForPage(page, 'Orders');
    const row = page.getByRole('row').filter({ hasText: 'Larkspur Dental Group' }).filter({ hasText: 'Pending' }).first();
    await moveTo(page, row.getByRole('cell').first(), { steps: 16, settle: 1500 });
    const orderNumber = (await row.getByRole('cell').first().innerText()).trim();

    console.log('[order-to-ship] Marking it shipped...');
    await clickOn(page, row.getByRole('link', { name: 'Edit' }));
    const status = page.locator('#status');
    await status.waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForLoadState('networkidle');
    await pause(700);
    await clickOn(page, status, { settle: 250 });
    await status.selectOption('shipped');
    await pause(900);
    await clickOn(page, page.getByRole('button', { name: /^(Update|Save)/ }).first());
    await waitForPage(page, 'Orders');
    const shipped = page.getByRole('row').filter({ hasText: orderNumber }).getByText('Shipped');
    await moveTo(page, shipped, { steps: 16, settle: 300 });
    // Step aside so the pointer does not cover the badge.
    await page.mouse.move(mouse.x + 46, mouse.y + 26, { steps: 8 });
    await pause(2800);
}

/** The full text of the <option> whose label starts with the product name. */
async function optionLabel(select, name) {
    const labels = await select.locator('option').allTextContents();
    const match = labels.map((l) => l.trim()).find((l) => l.startsWith(name));
    if (!match) throw new Error(`No product option for ${name}`);
    return match;
}

const FLOWS = {
    'receive-stock': { start: '/dashboard', run: receiveStockFlow },
    'order-to-ship': { start: '/dashboard', run: orderToShipFlow },
};

/** Writes an ffmpeg concat list that holds each frame until the next one arrived. */
function writeConcatList(frames, endT, framesDir) {
    const list = resolve(framesDir, 'frames.txt');
    const lines = [];
    frames.forEach((frame, i) => {
        const next = i + 1 < frames.length ? frames[i + 1].t : endT;
        const duration = Math.max(0.001, next - frame.t);
        lines.push(`file '${frame.file.replace(/\\/g, '/')}'`, `duration ${duration.toFixed(3)}`);
    });
    // The concat demuxer ignores the last entry's duration unless it is repeated.
    lines.push(`file '${frames[frames.length - 1].file.replace(/\\/g, '/')}'`);
    writeFileSync(list, lines.join('\n') + '\n');
    return list;
}

function encodeGif(list, output, framesDir) {
    const palette = resolve(framesDir, 'palette.png');
    const scale = `fps=${GIF_FPS},scale=${GIF_WIDTH}:-1:flags=lanczos`;
    const input = ['-f', 'concat', '-safe', '0', '-i', list];
    // One palette for the whole clip. The UI is flat colour and small text, so
    // dithering only adds noise (and bytes); rectangle diffing keeps frames that
    // differ by a caret or a typed character tiny.
    execFileSync('ffmpeg', ['-y', '-v', 'error', ...input, '-vf', `${scale},palettegen=max_colors=256:stats_mode=full`, palette]);
    execFileSync('ffmpeg', [
        '-y',
        '-v',
        'error',
        ...input,
        '-i',
        palette,
        '-lavfi',
        `[0:v]${scale}[x];[x][1:v]paletteuse=dither=none:diff_mode=rectangle`,
        '-loop',
        '0',
        output,
    ]);
    rmSync(palette, { force: true });
}

function extractDebugFrames(output, name) {
    const duration = Number(
        execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', output]).toString().trim(),
    );
    const points = { early: 0.12, mid: 0.45, late: 0.7, end: 0.97 };
    for (const [label, fraction] of Object.entries(points)) {
        const file = resolve(dirname(output), `${name}_frame_${label}.png`);
        execFileSync('ffmpeg', ['-y', '-v', 'error', '-ss', (duration * fraction).toFixed(2), '-i', output, '-frames:v', '1', file]);
        console.log(`[debug] frame ${label}: ${file}`);
    }
}

async function signIn(context) {
    const page = await context.newPage();
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' });
    await page.fill('#email', DEMO_USER.email);
    await page.fill('#password', DEMO_USER.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL('**/dashboard', { timeout: 20000 });
    await page.close();
}

async function record(browser, name, flow) {
    const framesDir = resolve(RECORDINGS_DIR, name);
    rmSync(framesDir, { recursive: true, force: true });
    mkdirSync(framesDir, { recursive: true });

    const context = await browser.newContext({ viewport: VIEWPORT, colorScheme: 'dark', deviceScaleFactor: 1 });
    await context.addInitScript(() => {
        try {
            localStorage.setItem('theme', 'dark');
        } catch {}
    });
    await context.addInitScript(CURSOR_SCRIPT);
    await signIn(context);

    const page = await context.newPage();
    await page.goto(`${BASE_URL}${flow.start}`, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    mouse = { x: 640, y: 400 };
    await page.mouse.move(mouse.x, mouse.y);
    await pause(300);

    const cast = await startScreencast(page, framesDir);
    let failure = null;
    try {
        await flow.run(page);
    } catch (err) {
        failure = err;
    }
    const endT = await cast.stop();
    await pause(200);
    if (failure) await page.screenshot({ path: resolve(RECORDINGS_DIR, `${name}-failure.png`) }).catch(() => {});
    await context.close();

    if (failure) throw new Error(`${name} flow failed: ${failure.message}`);
    if (cast.frames.length < 20) throw new Error(`${name}: only ${cast.frames.length} frames captured`);
    console.log(`[record] ${name}: ${cast.frames.length} frames over ${(endT - cast.frames[0].t).toFixed(1)}s`);

    const output = resolve(OUTPUT_DIR, `${name}.gif`);
    encodeGif(writeConcatList(cast.frames, endT, framesDir), output, framesDir);
    const size = statSync(output).size;
    console.log(`[convert] ${output} (${(size / 1024 / 1024).toFixed(2)} MB)`);
    if (size > MAX_GIF_BYTES) throw new Error(`${name}.gif is over 5 MB; lower GIF_FPS or GIF_WIDTH`);
    if (process.env.DEMO_DEBUG_FRAMES) extractDebugFrames(output, name);
}

async function main() {
    checkDependencies();
    mkdirSync(OUTPUT_DIR, { recursive: true });
    const only = process.env.DEMO_ONLY;
    const names = only ? [only] : Object.keys(FLOWS);
    for (const name of names) if (!FLOWS[name]) throw new Error(`Unknown flow ${name}`);

    const browser = await chromium.launch({ headless: true });
    try {
        for (const name of names) {
            console.log(`[demo] Recording ${name} against ${BASE_URL}`);
            await record(browser, name, FLOWS[name]);
        }
    } finally {
        await browser.close();
    }
    if (!process.env.KEEP_FRAMES) rmSync(RECORDINGS_DIR, { recursive: true, force: true });
    console.log('[done]');
}

main().catch((err) => {
    console.error('[fatal]', err.message || err);
    process.exit(1);
});
