// Runs with Node's built-in test runner: `npm run test:js`.
//
// The purchase order forms preview the totals PurchaseOrderService computes
// on save: per-line tax from a rate (rounded once per rate), or a PO-level
// tax when no line has a rate. Shipping is never taxed.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { reactive } from 'vue';
import {
    hasLineTaxRates,
    lineNetCents,
    purchaseOrderTotals,
    usePurchaseOrderTotals,
} from '../../resources/js/composables/usePurchaseOrderTotals.js';
import { defaultTaxRate } from '../../resources/js/lib/purchaseOrderLine.js';

const books = { quantity: 3, unit_cost: '10.00', tax_rate: 5 };
const gadgets = { quantity: 2, unit_cost: '100.00', tax_rate: 27 };

test('a line net is quantity x unit cost', () => {
    assert.equal(lineNetCents({ quantity: 3, unit_cost: '10.10' }), 3030);
    assert.equal(lineNetCents({ quantity: '', unit_cost: '10.10' }), 0);
});

test('mixed rates give net, tax per rate and gross, matching the server', () => {
    const totals = purchaseOrderTotals({
        items: [
            books,
            gadgets,
            // 5% of two 0.10 lines together is 0.01, not 0.01 each.
            { quantity: 1, unit_cost: '0.10', tax_rate: 5 },
            { quantity: 1, unit_cost: '0.10', tax_rate: '5' },
        ],
        tax: 0,
        shipping: '15.00',
    });

    assert.equal(totals.subtotal, 230.2);
    assert.equal(totals.lineRates, true);
    assert.deepEqual(totals.lineTaxes, [1.5, 54, 0.01, 0]);
    assert.deepEqual(totals.lineGross, [31.5, 254, 0.11, 0.1]);
    assert.deepEqual(totals.taxByRate, [
        { rate: 27, taxable: 200, tax: 54 },
        { rate: 5, taxable: 30.2, tax: 1.51 },
    ]);
    assert.equal(totals.tax, 55.51);
    assert.equal(totals.total, 300.71);
});

test('without line rates the PO-level tax is used, plus any line tax amounts', () => {
    const totals = purchaseOrderTotals({
        items: [{ quantity: 3, unit_cost: '10.00', tax_rate: '' }, { quantity: 1, unit_cost: '20.00', tax_rate: null, tax: '1.25' }],
        tax: '2.50',
        shipping: 5,
    });

    assert.equal(totals.lineRates, false);
    assert.deepEqual(totals.taxByRate, []);
    assert.equal(totals.tax, 3.75);
    assert.equal(totals.total, 58.75);
});

test('once a line has a rate the PO-level tax is ignored', () => {
    const totals = purchaseOrderTotals({ items: [gadgets], tax: '99.00', shipping: 0 });
    assert.equal(totals.tax, 54);
    assert.equal(totals.total, 254);
});

test('a rate of 0 is a real rate', () => {
    assert.equal(hasLineTaxRates([{ tax_rate: 0 }]), true);
    assert.equal(hasLineTaxRates([{ tax_rate: '' }, { tax_rate: null }]), false);
    assert.deepEqual(purchaseOrderTotals({ items: [{ quantity: 1, unit_cost: 10, tax_rate: 0 }], tax: 3 }).taxByRate, [{ rate: 0, taxable: 10, tax: 0 }]);
});

test('the totals follow the form as it changes', () => {
    const form = reactive({ items: [{ ...gadgets }], tax: 0, shipping: 0 });
    const totals = usePurchaseOrderTotals(form);
    assert.equal(totals.value.tax, 54);
    form.items[0].tax_rate = 18;
    assert.equal(totals.value.tax, 36);
    form.items[0].tax_rate = '';
    form.tax = 7;
    assert.equal(totals.value.tax, 7);
});

test('a new line starts at the product rate', () => {
    assert.equal(defaultTaxRate({ tax_rate: '27.000' }), 27);
    assert.equal(defaultTaxRate({ tax_rate: '0.000' }), 0);
    assert.equal(defaultTaxRate({ tax_rate: null }), '');
    assert.equal(defaultTaxRate(null), '');
});
