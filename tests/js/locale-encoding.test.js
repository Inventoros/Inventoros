import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';

const directory = new URL('../../resources/js/i18n/locales/', import.meta.url);
const labels = [
    'dashboard.design.viewProduct',
    'purchaseOrders.receive.fillAll',
    'purchaseOrders.receive.fillRemaining',
    'quickReorder.createFailed',
    'components.emptyState.filteredHint',
    'stockTransfers.index.empty',
    'stockTransfers.index.emptyHint',
];

for (const file of readdirSync(directory).filter(file => file.endsWith('.json'))) {
    test(`${file} preserves readable UI copy through UTF-8 serialization`, () => {
        const source = readFileSync(new URL(file, directory), 'utf8');
        assert.doesNotMatch(source, /\uFFFD|\?{2,}/u, 'Catalog contains text replaced by an incompatible encoding');
        const catalog = JSON.parse(source);
        for (const path of labels) {
            const value = path.split('.').reduce((value, key) => value[key], catalog);
            assert.equal(typeof value, 'string', path);
            assert.doesNotMatch(value, /\?/, `${path} is a label, not a question; check its encoding`);
        }
    });
}
