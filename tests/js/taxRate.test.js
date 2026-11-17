// Runs with Node's built-in test runner: `npm run test:js`.
//
// Orders and purchase orders store tax as an AMOUNT. The optional tax rate
// helper on those forms fills that amount in from a percentage of the taxable
// subtotal: merchandise after line and order discounts, before shipping
// (#261: a rate typed into the amount field was saved as money).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { reactive } from 'vue';
import { taxFromRate, useOrderTotals } from '../../resources/js/composables/useOrderTotals.js';

test('the tax amount is the rate applied to the taxable subtotal, rounded half up to the cent', () => {
    assert.equal(taxFromRate(100, 13), 13);
    assert.equal(taxFromRate(19.99, 8.875), 1.77); // 1.7741...
    assert.equal(taxFromRate(10.1, 5), 0.51); // 0.505 rounds up
    assert.equal(taxFromRate(1234.56, 0), 0);
});

test('an empty, negative or unreadable rate or base gives no tax', () => {
    assert.equal(taxFromRate(100, ''), 0);
    assert.equal(taxFromRate(100, null), 0);
    assert.equal(taxFromRate(100, -5), 0);
    assert.equal(taxFromRate(100, 'abc'), 0);
    assert.equal(taxFromRate(0, 13), 0);
    assert.equal(taxFromRate(-20, 13), 0);
});

test('a rate over 100% is capped at 100%', () => {
    assert.equal(taxFromRate(50, 250), 50);
});

test('the order totals expose the taxable subtotal after line and order discounts', () => {
    const form = reactive({
        items: [
            { unit_price: '10.00', quantity: 3, discount_type: 'fixed', discount_value: 5 }, // 30 - 5
            { unit_price: '20.00', quantity: 1, discount_type: '', discount_value: null }, // 20
        ],
        discount_type: 'percent',
        discount_value: 10, // 10% of 45 = 4.50
        tax: 0,
        shipping: 12,
    });

    const totals = useOrderTotals(form);

    assert.equal(totals.value.taxable, 40.5);
    assert.equal(taxFromRate(totals.value.taxable, 13), 5.27); // 5.265 rounds up
});

test('once a line has a tax rate, the order tax is the sum of the line taxes (#416)', () => {
    const form = reactive({
        items: [
            { unit_price: '10.00', quantity: 2, discount_type: 'fixed', discount_value: 2, tax_rate: 5 }, // 18 net
            { unit_price: '20.00', quantity: 1, discount_type: '', discount_value: null, tax_rate: '27' }, // 20 net
        ],
        discount_type: 'percent',
        discount_value: 10, // 3.80, shared: taxed on 16.20 and 18.00
        tax: 99, // an order-level tax is not used alongside line rates
        shipping: 5,
    });

    const totals = useOrderTotals(form);

    assert.equal(totals.value.lineRates, true);
    assert.deepEqual(totals.value.lineTaxes, [0.81, 4.86]);
    assert.equal(totals.value.tax, 5.67);
    assert.equal(totals.value.total, 44.87);
    assert.deepEqual(totals.value.taxByRate.map((entry) => entry.rate), [27, 5]);
});

test('without line rates, the order-level tax and line tax amounts add up as before', () => {
    const form = reactive({
        items: [{ unit_price: '10.00', quantity: 1, tax_rate: '', tax: 2 }],
        discount_type: '',
        discount_value: null,
        tax: 3,
        shipping: 0,
    });

    const totals = useOrderTotals(form);

    assert.equal(totals.value.lineRates, false);
    assert.equal(totals.value.tax, 5);
    assert.equal(totals.value.total, 15);
});
