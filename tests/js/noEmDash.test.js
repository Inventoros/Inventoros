// Runs with Node's built-in test runner: `npm run test:js`.
//
// User-facing copy uses no em dashes. Catches them in component markup and
// in the English locale file (comments are ignored).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const root = join(import.meta.dirname, '..', '..', 'resources', 'js');
const EM_DASH = '\u2014';

function vueFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return vueFiles(path);
        return name.endsWith('.vue') ? [path] : [];
    });
}

test('no em dash in component templates', () => {
    const offenders = [];
    for (const file of vueFiles(root)) {
        const template = readFileSync(file, 'utf8')
            .replace(/<script\b[\s\S]*?<\/script>/g, '')
            .replace(/<style\b[\s\S]*?<\/style>/g, '')
            .replace(/<!--[\s\S]*?-->/g, '');
        if (template.includes(EM_DASH)) offenders.push(relative(root, file));
    }
    assert.deepEqual(offenders, []);
});

test('no em dash in English UI strings', () => {
    const offenders = [];
    const walk = (node, path) => {
        for (const [key, value] of Object.entries(node)) {
            const here = path ? `${path}.${key}` : key;
            if (typeof value === 'string' && value.includes(EM_DASH)) offenders.push(here);
            else if (value && typeof value === 'object') walk(value, here);
        }
    };
    walk(JSON.parse(readFileSync(join(root, 'i18n', 'locales', 'en.json'), 'utf8')), '');
    assert.deepEqual(offenders, []);
});
