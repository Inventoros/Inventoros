// Runs with Node's built-in test runner: `npm run test:js`.
//
// Every word a user reads goes through vue-i18n, so all 13 translations cover
// every screen. This fails when a component writes English straight into its
// template (text, placeholder/title/aria-label/label/description and similar
// attributes, string literals in template expressions) or into a confirm() /
// alert() in its script. Use t('namespace.key') and add the key to en.json and
// every other locale (i18nParity.test.js checks the locales).
//
// Brand names, acronyms and codes are allowed by tests/js/support/hardcodedText.js.
// ALLOWED below is for the rare literal value that is the same in every
// language (a technical default shown as a placeholder); keep it short.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { findHardcodedText, hasTranslatableWords } from './support/hardcodedText.js';

const root = join(import.meta.dirname, '..', '..', 'resources', 'js');

// file (relative to resources/js) -> literal texts allowed there
const ALLOWED = {
    // Installer defaults: literal database settings, not prose.
    'Pages/Install/Database.vue': ['localhost', 'inventoros', 'root'],
};

function vueFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return vueFiles(path);
        return name.endsWith('.vue') ? [path] : [];
    });
}

test('the detector flags English and lets codes, brands and keys through', () => {
    assert.equal(hasTranslatableWords('No returns found.'), true);
    assert.equal(hasTranslatableWords('Status'), true);
    assert.equal(hasTranslatableWords('SKU'), false);
    assert.equal(hasTranslatableWords('SKU-001'), false);
    assert.equal(hasTranslatableWords('you@example.com'), false);
    assert.equal(hasTranslatableWords('Inventoros'), false);
    assert.equal(hasTranslatableWords(' / '), false);
    assert.equal(hasTranslatableWords('&times;'), false);

    const found = findHardcodedText(`
<script setup>
const remove = () => { if (confirm('Delete this item?')) go(); };
</script>
<template>
    <Head :title="t('returns.title')" />
    <PageHeader title="Returns" :description="t('returns.subtitle')" />
    <input placeholder="Search..." class="h-9 w-full" />
    <Badge :class="active ? 'bg-green text-sm' : 'text-red'">{{ active ? 'Active' : t('common.inactive') }}</Badge>
    <span>{{ t('common.total') }}: {{ total }} SKU</span>
    <code>php artisan migrate</code>
</template>`);
    assert.deepEqual(found.map((f) => f.text).sort(), ['Active', 'Delete this item?', 'Returns', 'Search...']);
});

test('no hard-coded English in component templates', () => {
    const offenders = [];
    for (const file of vueFiles(root)) {
        const name = relative(root, file).split('\\').join('/');
        const allowed = new Set(ALLOWED[name] || []);
        for (const hit of findHardcodedText(readFileSync(file, 'utf8'))) {
            if (!allowed.has(hit.text)) offenders.push(`${name}:${hit.line} ${hit.kind}: ${hit.text}`);
        }
    }
    assert.deepEqual(offenders, [], `${offenders.length} hard-coded string(s); move them to the locale files`);
});
