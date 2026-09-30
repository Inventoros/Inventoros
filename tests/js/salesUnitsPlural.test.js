// Runs with Node's built-in test runner: `npm run test:js`.
//
// Sales Analysis read "1 units sold": the count and a fixed "units sold"
// label. The label is now one plural message.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { createI18n } from 'vue-i18n';

const en = JSON.parse(readFileSync(join(import.meta.dirname, '..', '..', 'resources', 'js', 'i18n', 'locales', 'en.json'), 'utf8'));
const { t } = createI18n({ legacy: false, locale: 'en', messages: { en } }).global;

test('one unit sold is singular, more are plural', () => {
    assert.equal(t('salesUnits.sold', 1), '1 unit sold');
    assert.equal(t('salesUnits.sold', 3), '3 units sold');
});

test('the Sales Analysis page uses the plural message', () => {
    const page = readFileSync(join(import.meta.dirname, '..', '..', 'resources', 'js', 'Pages', 'Reports', 'SalesAnalysis.vue'), 'utf8');
    assert.match(page, /t\('salesUnits\.sold', product\.quantity_sold\)/);
});
