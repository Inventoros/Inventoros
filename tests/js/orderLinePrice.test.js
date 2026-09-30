// Runs with Node's built-in test runner: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { linePrice } from '../../resources/js/lib/orderLinePrice.js';

const syrup = { price: '12.00', currency: 'CAD', prices: { USD: '11.00', EUR: '10.50' } };

test('in the product currency: the variant price, else the product price', () => {
    assert.equal(linePrice(syrup, null, 'CAD'), 12);
    assert.equal(linePrice(syrup, { price: '14.00', own_price: true }, 'CAD'), 14);
});

test('in another currency: the product price for that currency', () => {
    assert.equal(linePrice(syrup, null, 'usd'), 11);
    assert.equal(linePrice(syrup, { price: '12.00', own_price: false }, 'EUR'), 10.5);
});

test('no price in that currency (or a variant with its own price): nothing to prefill', () => {
    assert.equal(linePrice(syrup, null, 'GBP'), null);
    assert.equal(linePrice(syrup, { price: '14.00', own_price: true }, 'USD'), null);
});
