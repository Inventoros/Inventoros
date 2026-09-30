// Runs with Node's built-in test runner: `npm run test:js`.
//
// Guards against a <script setup> block calling a composable it never
// imported. There is no auto-import in this app, so the page compiles fine and
// only throws `ReferenceError: useI18n is not defined` when the component
// mounts (QuickAddModal did exactly that).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const root = join(import.meta.dirname, '..', '..', 'resources', 'js');

// Identifiers that are always imported, never globals.
const IMPORTED_CALLS = [
    'useI18n',
    'usePage',
    'useForm',
    'ref',
    'computed',
    'reactive',
    'watch',
    'watchEffect',
    'onMounted',
    'onBeforeUnmount',
    'onUnmounted',
    'nextTick',
];

function vueFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return vueFiles(path);
        return name.endsWith('.vue') ? [path] : [];
    });
}

function scriptOf(source) {
    return [...source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)]
        .map((m) => m[1])
        .join('\n')
        // Drop comments so a mention in prose does not count as a call.
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/.*$/gm, '$1');
}

function isDeclared(script, name) {
    const imported = new RegExp(`import\\s*\\{[^}]*\\b${name}\\b[^}]*\\}\\s*from`);
    const local = new RegExp(`(?:const|let|var|function)\\s+${name}\\b|\\{[^}]*\\b${name}\\b[^}]*\\}\\s*=`);
    return imported.test(script) || local.test(script);
}

test('every composable a Vue component calls is imported', () => {
    const missing = [];

    for (const file of vueFiles(root)) {
        const script = scriptOf(readFileSync(file, 'utf8'));
        for (const name of IMPORTED_CALLS) {
            const called = new RegExp(`(?<![\\w.$])${name}\\s*\\(`).test(script);
            if (called && !isDeclared(script, name)) {
                missing.push(`${relative(root, file)}: ${name}()`);
            }
        }
    }

    assert.deepEqual(missing, [], `Called but never imported:\n${missing.join('\n')}`);
});
