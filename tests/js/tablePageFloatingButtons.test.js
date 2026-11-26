import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

// Table pages pin their pagination to the bottom of the viewport, so a
// button fixed to the bottom corner sits on top of "Next" (issue #482).
// Page actions belong in the PageHeader instead.

const pagesDir = new URL('../../resources/js/Pages/', import.meta.url);

function vueFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        return statSync(path).isDirectory() ? vueFiles(path) : path.endsWith('.vue') ? [path] : [];
    });
}

const tablePages = vueFiles(pagesDir.pathname.replace(/^\/([A-Za-z]:)/, '$1'))
    .map((path) => ({ path, source: readFileSync(path, 'utf8') }))
    .filter(({ source }) => /<AppLayout[^>]*\btable-page\b/.test(source));

test('there are table pages to check', () => {
    assert.ok(tablePages.length > 0);
});

test('table pages have no button fixed to the bottom of the screen', () => {
    const offenders = tablePages.filter(({ source }) =>
        /<button\b[^>]*class="[^"]*\bfixed\b[^"]*\bbottom-/s.test(source),
    );
    assert.deepEqual(offenders.map(({ path }) => path), []);
});

test('the products page offers the barcode scanner from its header', () => {
    const page = tablePages.find(({ path }) => /Products[\\/]Index\.vue$/.test(path));
    assert.ok(page, 'Products/Index.vue is a table page');
    const actions = page.source.match(/<template #actions>([\s\S]*?)<\/template>/);
    assert.ok(actions, 'PageHeader has an actions slot');
    assert.match(actions[1], /@click="openScanner"/);
});
