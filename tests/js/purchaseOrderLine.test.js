// Runs with Node's built-in test runner: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { defaultUnitCost, defaultSupplierSku, supplierLink } from '../../resources/js/lib/purchaseOrderLine.js';

const product = {
    id: 1,
    price: '25.00',
    purchase_price: '10.00',
    supplier_costs: [
        { supplier_id: 7, cost_price: '8.40', supplier_sku: 'ACME-001' },
        { supplier_id: 9, cost_price: null, supplier_sku: null },
    ],
};

test('the linked supplier cost wins over the product purchase price', () => {
    assert.equal(defaultUnitCost(product, null, 7), 8.4);
    // A select value arrives as a string.
    assert.equal(defaultUnitCost(product, null, '7'), 8.4);
});

test('the linked supplier cost wins for a variant too', () => {
    assert.equal(defaultUnitCost(product, { purchase_price: '12.00' }, 7), 8.4);
});

test('without a link (or a link with no cost) it falls back to purchase prices', () => {
    assert.equal(defaultUnitCost(product, null, 3), 10);
    assert.equal(defaultUnitCost(product, null, 9), 10);
    assert.equal(defaultUnitCost(product, null, ''), 10);
    assert.equal(defaultUnitCost(product, { purchase_price: '12.00' }, 3), 12);
    assert.equal(defaultUnitCost({ price: '25.00' }), 25);
    assert.equal(defaultUnitCost(null), 0);
});

test('the supplier SKU comes from the link for the chosen supplier only', () => {
    assert.equal(defaultSupplierSku(product, 7), 'ACME-001');
    assert.equal(defaultSupplierSku(product, 9), '');
    assert.equal(defaultSupplierSku(product, 3), '');
    assert.equal(defaultSupplierSku({ id: 2 }, 7), '');
    assert.equal(supplierLink(product, null), null);
});
