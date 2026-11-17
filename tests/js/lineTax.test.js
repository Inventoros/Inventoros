// Runs with Node's built-in test runner: `npm run test:js`.
// Mirrors tests/Unit/LineTaxTest.php: the forms must preview what the server saves.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    allocateCents,
    computeLineTaxes,
    formatTaxRate,
    grossFromNet,
    netFromGross,
    rateValue,
    taxByRate,
    taxCents,
} from '../../resources/js/lib/lineTax.js';

test('a blank rate is no rate, and 0 is a rate', () => {
    assert.equal(rateValue(''), null);
    assert.equal(rateValue(null), null);
    assert.equal(rateValue('0'), 0);
    assert.equal(rateValue('27.000'), 27);
});

test('tax rounds half away from zero to the cent', () => {
    assert.equal(taxCents(50, 27), 14); // 0.135 -> 0.14
    assert.equal(taxCents(10, 5), 1); // 0.005 -> 0.01
    assert.equal(taxCents(1000, '9.375'), 94); // 0.9375 -> 0.94
    assert.equal(taxCents(1000, ''), 0);
});

test('allocation keeps every cent in line order', () => {
    assert.deepEqual(allocateCents(1000, [1, 1, 1]), [333, 334, 333]);
    assert.deepEqual(allocateCents(500, [0, 0]), [0, 0]);
});

test('each rate applies to its own lines; lines without a rate keep their amount', () => {
    const taxed = computeLineTaxes([
        { netCents: 10000, taxRate: 5 },
        { netCents: 1000, taxRate: '27' },
        { netCents: 5000, taxRate: '', taxCents: 321 },
    ]);
    assert.deepEqual(taxed.map((line) => line.taxCents), [500, 270, 321]);
});

test('the order discount is shared across all lines before tax', () => {
    const taxed = computeLineTaxes(
        [
            { netCents: 10000, taxRate: 27 },
            { netCents: 5000, taxRate: 5 },
            { netCents: 5000, taxCents: 100 },
        ],
        2000,
    );
    assert.deepEqual(taxed.map((line) => line.taxableCents), [9000, 4500, 4500]);
    assert.deepEqual(taxed.map((line) => line.taxCents), [2430, 225, 100]);
});

test('tax per rate is rounded once and split back across its lines', () => {
    const taxed = computeLineTaxes([
        { netCents: 10, taxRate: 27 },
        { netCents: 10, taxRate: 27 },
        { netCents: 10, taxRate: 27 },
    ]);
    assert.deepEqual(taxed.map((line) => line.taxCents), [3, 2, 3]);
    assert.deepEqual(taxByRate(taxed), [{ rate: 27, taxableCents: 30, taxCents: 8 }]);
});

test('gross and net convert both ways at a rate', () => {
    assert.equal(grossFromNet('10.00', 27), 12.7);
    assert.equal(netFromGross('12.70', 27), 10);
    assert.equal(netFromGross('9.99', 27), 7.87);
    assert.equal(grossFromNet(7.87, 27), 9.99);
    assert.equal(grossFromNet('10.00', ''), null);
    assert.equal(netFromGross('', 27), null);
});

test('rates display without trailing zeros', () => {
    assert.equal(formatTaxRate('27.000'), '27%');
    assert.equal(formatTaxRate('5.5'), '5.5%');
    assert.equal(formatTaxRate(''), '');
});
