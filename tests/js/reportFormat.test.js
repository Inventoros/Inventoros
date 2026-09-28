// `npm run test:js`, pinned west of UTC like dates.test.js.
process.env.TZ = 'America/Los_Angeles';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatCurrency, formatDay, formatTimestampDate, otherCurrencyTotals } from '../../resources/js/lib/reportFormat.js';

test('formatDay shows a calendar-day value (e.g. MAX(order_date)) on its own day', () => {
    assert.equal(formatDay('2026-08-18 00:00:00', 'en-US'), '8/18/2026');
    assert.equal(formatDay('2026-08-18', 'en-US'), '8/18/2026');
    assert.equal(formatDay(null), '-');
});

test('formatTimestampDate shows a UTC timestamp (e.g. MAX(created_at)) on the viewer\'s day', () => {
    // Read as local time this would have shown 9/27.
    assert.equal(formatTimestampDate('2026-09-27 03:00:00', 'en-US'), '9/26/2026');
    assert.equal(formatTimestampDate(null), '-');
});

test('formatCurrency formats in the given currency, USD by default', () => {
    assert.equal(formatCurrency(12.5), '$12.50');
    assert.equal(formatCurrency('40', 'CAD'), 'CA$40.00');
    assert.equal(formatCurrency(3, 'ZZZZ'), '3.00 ZZZZ');
});

test('otherCurrencyTotals lists the other currencies without adding them to the base', () => {
    const values = [
        { currency: 'CAD', amount: 40 },
        { currency: 'EUR', amount: 10 },
        { currency: 'USD', amount: 0 },
        { currency: 'GBP', amount: 5 },
    ];
    assert.equal(otherCurrencyTotals(values, 'CAD'), '€10.00, £5.00');
    assert.equal(otherCurrencyTotals([{ currency: 'CAD', amount: 1 }], 'CAD'), '');
    assert.equal(otherCurrencyTotals(undefined, 'CAD'), '');
});
