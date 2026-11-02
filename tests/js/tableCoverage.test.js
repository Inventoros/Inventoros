import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parse as parseSfc } from '@vue/compiler-sfc';
import { parse } from '@vue/compiler-dom';

const root = fileURLToPath(new URL('../../resources/js', import.meta.url));
function* vueFiles(directory) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const file = join(directory, entry.name);
        if (entry.isDirectory()) yield* vueFiles(file);
        else if (file.endsWith('.vue')) yield file;
    }
}

test('every application table has the shared scrolling and full-view behavior', () => {
    const uncovered = [];
    let tables = 0;
    for (const file of vueFiles(root)) {
        const { descriptor } = parseSfc(readFileSync(file, 'utf8'));
        if (!descriptor.template) continue;
        const walk = (node, inViewport = false) => {
            const covered = inViewport || node.tag === 'TableViewport';
            if (node.tag === 'table') {
                tables++;
                if (!covered)
                    uncovered.push(
                        `${relative(root, file)}:${node.loc.start.line}`,
                    );
            }
            for (const child of node.children || []) walk(child, covered);
        };
        walk(parse(descriptor.template.content));
    }
    assert.ok(tables > 0, 'The audit must inspect actual table templates');
    assert.deepEqual(
        uncovered,
        [],
        `Tables missing scrolling/full view: ${uncovered.join(', ')}`,
    );
});
