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

// #429: a HUF product bought from a EUR supplier.
const hufProduct = {
    id: 2,
    currency: 'HUF',
    price: '9000.00',
    purchase_price: '3000.00',
    supplier_costs: [
        { supplier_id: 7, cost_price: '12.50', cost_currency: 'EUR', supplier_sku: 'EU-1' },
        { supplier_id: 8, cost_price: '2900.00', cost_currency: 'HUF', supplier_sku: 'HU-1' },
    ],
};

test('the linked cost is used on a PO in the link currency', () => {
    assert.equal(defaultUnitCost(hufProduct, null, 7, 'EUR'), 12.5);
    assert.equal(defaultUnitCost(hufProduct, null, 7, 'eur'), 12.5);
    assert.equal(defaultUnitCost(hufProduct, null, 8, 'HUF'), 2900);
});

test('a cost in another currency than the PO is never copied over', () => {
    // EUR link on a HUF PO: falls back to the HUF purchase price.
    assert.equal(defaultUnitCost(hufProduct, null, 7, 'HUF'), 3000);
    // HUF link and HUF prices on a EUR PO: nothing in EUR, start at zero.
    assert.equal(defaultUnitCost(hufProduct, null, 8, 'EUR'), 0);
    assert.equal(defaultUnitCost(hufProduct, { purchase_price: '3100.00' }, 3, 'EUR'), 0);
    assert.equal(defaultUnitCost(hufProduct, { purchase_price: '3100.00' }, 3, 'HUF'), 3100);
});

test('without a PO currency, or currencies in the payload, amounts are used as before', () => {
    assert.equal(defaultUnitCost(hufProduct, null, 7), 12.5);
    assert.equal(defaultUnitCost(product, null, 7, 'EUR'), 8.4);
    assert.equal(defaultUnitCost(product, null, 3, 'EUR'), 10);
});
