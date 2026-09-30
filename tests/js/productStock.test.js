// Runs with Node's built-in test runner: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { onHandStock, stockStatus, stockValue } from '../../resources/js/lib/productStock.js';

const tee = { has_variants: true, stock: 0, min_stock: 2, price: '20.00' };
const variants = [
    { stock: 3, price: null, is_active: true },
    { stock: 2, price: '25.00', is_active: true },
    { stock: 9, price: '20.00', is_active: false },
];

test('a product sold by variant has its active variants on hand', () => {
    assert.equal(onHandStock(tee, variants), 5);
});

test('a flagged product with no variants yet, or a plain product, uses its own stock', () => {
    assert.equal(onHandStock(tee, []), 0);
    assert.equal(onHandStock({ has_variants: false, stock: 7 }, variants), 7);
});

test('the server figure wins when the page has it', () => {
    assert.equal(onHandStock({ ...tee, effective_stock: 11 }, variants), 11);
});

test('each variant is valued at its own price, else the product price', () => {
    assert.equal(stockValue(tee, variants, 'price'), 3 * 20 + 2 * 25);
    assert.equal(stockValue({ has_variants: false, stock: 4, price: '2.50' }, [], 'price'), 10);
});

test('status reads the on-hand figure, not products.stock', () => {
    assert.equal(stockStatus(tee, variants), 'in_stock');
    assert.equal(stockStatus({ ...tee, min_stock: 5 }, variants), 'low_stock');
    assert.equal(stockStatus(tee, [{ stock: 0, is_active: true }]), 'out_of_stock');
});
