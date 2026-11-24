import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { appIcons } from '../../resources/js/icons/index.js';

const render = (component, props = {}) => renderToString(createSSRApp({ render: () => h(component, props) }));

test('every icon in appIcons renders a 24px-grid svg with drawn content', async () => {
    const names = Object.keys(appIcons);
    assert.ok(names.length > 0);
    for (const name of names) {
        const html = await render(appIcons[name]);
        assert.match(html, /^<svg\b[^>]*\bviewBox="0 0 24 24"/, name);
        assert.match(html, /^<svg\b[^>]*>\s*<(path|rect|circle)\b/, `${name} has no child shape`);
    }
});

test('icons are decorative unless labelled, and Lucide-only props stay off the svg', async () => {
    const { IconInventory } = appIcons;
    assert.match(await render(IconInventory), /aria-hidden="true"/);
    for (const label of [{ 'aria-label': 'Inventory' }, { 'aria-labelledby': 'inventory-title' }, { title: 'Inventory' }]) {
        assert.doesNotMatch(await render(IconInventory, label), /aria-hidden/, JSON.stringify(label));
    }
    const html = await render(IconInventory, { absoluteStrokeWidth: true, size: 16 });
    assert.doesNotMatch(html, /absolute/i);
    assert.match(html, /width="16"/);
});
