// Runs with Node's built-in test runner: `npm run test:js`.
//
// en.json is the source locale. Every other locale must carry every en key,
// with the same {placeholders} and the same number of plural forms, so no
// screen silently falls back to English and no interpolation renders a raw
// "{count}". Each message must also compile under vue-i18n's syntax: a stray
// "@" or "|" in a translation breaks the string at runtime, not at build.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { baseCompile } from '@intlify/message-compiler';

const localesDir = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/i18n/locales');
const read = (file) => JSON.parse(readFileSync(join(localesDir, file), 'utf8'));

const flatten = (obj, prefix = '', out = {}) => {
    for (const [key, value] of Object.entries(obj)) {
        const path = prefix ? `${prefix}.${key}` : key;
        if (value !== null && typeof value === 'object') flatten(value, path, out);
        else out[path] = value;
    }
    return out;
};

const placeholders = (message) => [...message.matchAll(/\{\s*([\w.]+)\s*\}/g)].map((m) => m[1]).sort();
const pluralForms = (message) => message.split('|').length;

const en = flatten(read('en.json'));
const locales = readdirSync(localesDir).filter((f) => f.endsWith('.json') && f !== 'en.json');

test('there are 13 non-English locales to check', () => {
    assert.equal(locales.length, 13);
});

for (const file of locales) {
    const locale = flatten(read(file));

    test(`${file} has every en key`, () => {
        const missing = Object.keys(en).filter((key) => !(key in locale));
        assert.deepEqual(missing, [], `${file} is missing ${missing.length} key(s)`);
    });

    test(`${file} has no keys that en does not`, () => {
        const extra = Object.keys(locale).filter((key) => !(key in en));
        assert.deepEqual(extra, []);
    });

    test(`${file} keeps every en placeholder and plural form`, () => {
        const mismatched = [];
        for (const [key, source] of Object.entries(en)) {
            const value = locale[key];
            if (typeof value !== 'string' || typeof source !== 'string') continue;
            if (JSON.stringify(placeholders(value)) !== JSON.stringify(placeholders(source))) {
                mismatched.push(`${key}: placeholders ${placeholders(value)} != ${placeholders(source)}`);
            }
            if (pluralForms(value) !== pluralForms(source)) {
                mismatched.push(`${key}: ${pluralForms(value)} plural forms != ${pluralForms(source)}`);
            }
        }
        assert.deepEqual(mismatched, []);
    });

    test(`${file} has no empty values or em dashes`, () => {
        const bad = Object.entries(locale)
            .filter(([, value]) => typeof value !== 'string' || value.trim() === '' || value.includes('—'))
            .map(([key]) => key);
        assert.deepEqual(bad, []);
    });
}

for (const file of ['en.json', ...locales]) {
    test(`${file} gives every sidebar nav item a distinct label`, () => {
        const nav = read(file).nav;
        const seen = {};
        for (const [key, value] of Object.entries(nav)) {
            if (typeof value === 'string') (seen[value] ||= []).push(key);
        }
        const clashes = Object.entries(seen).filter(([, keys]) => keys.length > 1);
        assert.deepEqual(clashes, []);

        // The Stock section heading must not read the same as the Inventory
        // item above it, or the sidebar shows one word twice.
        assert.notEqual(nav.sections.stock.toLowerCase(), nav.inventory.toLowerCase());
    });

    test(`${file} messages all compile`, () => {
        const broken = [];
        for (const [key, value] of Object.entries(flatten(read(file)))) {
            if (typeof value !== 'string') continue;
            baseCompile(value, {
                onError: (error) => broken.push(`${key}: ${error.message}`),
            });
        }
        assert.deepEqual(broken, []);
    });
}
