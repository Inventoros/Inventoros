import test from 'node:test';
import assert from 'node:assert/strict';
import { estimateRefund } from '../../resources/js/lib/refundEstimate.js';

const line = (extra = {}) => ({ paidNet: '2.00', orderedQuantity: 3, returnQuantity: 1, alreadyReturned: 0, alreadyRefunded: '0.00', ...extra });

test('partial estimates retain exactly the paid cents', () => {
    assert.equal(estimateRefund([line()]), '0.67');
    assert.equal(estimateRefund([line({ alreadyReturned: 1, alreadyRefunded: '0.67' })]), '0.66');
    assert.equal(estimateRefund([line({ alreadyReturned: 2, alreadyRefunded: '1.33' })]), '0.67');
});

test('a rejected earlier request releases its actual rounding cent', () => {
    assert.equal(estimateRefund([line({ alreadyReturned: 1, alreadyRefunded: '0.66' })]), '0.67');
});

test('the order balance caps estimates after legacy over-rounding', () => {
    assert.equal(estimateRefund([line({ alreadyReturned: 1, alreadyRefunded: '0.67' })], '0.20'), '0.20');
    assert.equal(estimateRefund([line()], '0.00'), '0.00');
});

test('half-cent ties and large totals use exact integer arithmetic', () => {
    assert.equal(estimateRefund([line({ paidNet: '0.01', orderedQuantity: 2 })]), '0.01');
    assert.equal(estimateRefund([line({ paidNet: '99999999.99', orderedQuantity: 1 })]), '99999999.99');
    assert.equal(estimateRefund([line({ paidNet: '1.005' })]), '0.00');
});

test('zero, invalid and excessive quantities cannot create a negative or excessive estimate', () => {
    assert.equal(estimateRefund([line({ returnQuantity: 0 })]), '0.00');
    assert.equal(estimateRefund([line({ returnQuantity: -1 })]), '0.00');
    assert.equal(estimateRefund([line({ orderedQuantity: 0 })]), '0.00');
    assert.equal(estimateRefund([line({ returnQuantity: 999 })]), '2.00');
    assert.equal(estimateRefund([line({ alreadyRefunded: '3.00' })]), '0.00');
});
