// Runs with Node's built-in test runner: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { invoiceState } from '../../resources/js/lib/invoiceState.js';

test('no invoice number: nothing issued, no badge', () => {
    assert.deepEqual(invoiceState({}), { badge: null, note: 'notIssued' });
});

// The card used to show an "Issued" badge and the invoice number next to
// "Not issued yet" once the invoice was generated but not emailed.
test('generated but never emailed: issued badge, not-emailed note', () => {
    assert.deepEqual(invoiceState({ invoice_number: 'INV-000001' }), { badge: 'issued', note: 'notEmailed' });
});

test('emailed and delivered: sent', () => {
    const order = { invoice_number: 'INV-000001', invoice_queued_at: '2026-09-01T10:00:00Z', invoice_sent_at: '2026-09-01T10:01:00Z' };
    assert.deepEqual(invoiceState(order), { badge: 'sent', note: 'sent' });
});

test('queued and not delivered yet: queued', () => {
    const order = { invoice_number: 'INV-000001', invoice_queued_at: '2026-09-01T10:00:00Z' };
    assert.deepEqual(invoiceState(order), { badge: 'queued', note: 'queued' });
});

test('re-sent after an earlier delivery: the newer queued stamp wins', () => {
    const order = { invoice_number: 'INV-000001', invoice_queued_at: '2026-09-02T10:00:00Z', invoice_sent_at: '2026-09-01T10:01:00Z' };
    assert.deepEqual(invoiceState(order), { badge: 'queued', note: 'queued' });
});
