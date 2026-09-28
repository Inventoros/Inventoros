// Runs with Node's built-in test runner: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatCompactMoney, formatMoney } from '../../resources/js/lib/money.js';

test('amounts are grouped, two decimals, in the given currency', () => {
    assert.equal(formatMoney(1234.5, 'USD', 'en-US'), '$1,234.50');
    assert.equal(formatMoney('1234.5', 'cad', 'en-CA'), '$1,234.50');
    assert.equal(formatMoney(1234.5, 'EUR', 'en-US'), '€1,234.50');
});

test('blank and invalid input formats as zero, unknown codes still show the amount', () => {
    assert.equal(formatMoney(null, 'USD', 'en-US'), '$0.00');
    assert.equal(formatMoney('', undefined, 'en-US'), '$0.00');
    assert.equal(formatMoney(5, 'NOT-A-CODE', 'en-US'), '5.00 NOT-A-CODE');
});

test('compact amounts keep the currency, small ones keep their cents', () => {
    assert.equal(formatCompactMoney(234100, 'USD', 'en-US'), '$234.1K');
    assert.equal(formatCompactMoney(1400, 'CAD', 'en-US'), 'CA$1.4K');
    assert.equal(formatCompactMoney(999.5, 'USD', 'en-US'), '$999.50');
});
