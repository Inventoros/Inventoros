// `npm run test:js`, pinned west of UTC like dates.test.js.
process.env.TZ = 'America/Los_Angeles';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatCurrency, formatDay, formatTimestampDate, otherCurrencyTotals } from '../../resources/js/lib/reportFormat.js';

test('formatDay shows a calendar-day value (e.g. MAX(order_date)) on its own day', () => {
    assert.equal(formatDay('2026-08-18 00:00:00'), 'Aug 18, 2026');
    assert.equal(formatDay('2026-08-18'), 'Aug 18, 2026');
    assert.equal(formatDay(null), '-');
});

test('formatTimestampDate shows a UTC timestamp (e.g. MAX(created_at)) on the viewer\'s day', () => {
    // Read as local time this would have shown Sep 27.
    assert.equal(formatTimestampDate('2026-09-27 03:00:00'), 'Sep 26, 2026');
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

test('report figures follow the active UI locale and the organization currency', async () => {
    const { applyFormatSettings } = await import('../../resources/js/lib/formatSettings.js');
    const { formatNumber, formatPercent, formatDelta } = await import('../../resources/js/lib/reportFormat.js');
    const plain = (s) => s.replace(/[\u202f\u00a0]/g, ' ');
    try {
        applyFormatSettings({ locale: 'de', currency: 'EUR' });
        assert.equal(plain(formatCurrency(1234.5)), '1.234,50 €');
        assert.equal(formatNumber(12345), '12.345');
        assert.equal(plain(formatPercent(12.5)), '12,5 %');
        assert.equal(plain(formatDelta(-3.25)), '-3,3 %');
        applyFormatSettings({ locale: 'fr' });
        assert.equal(plain(formatPercent(12.5)), '12,5 %');
        applyFormatSettings({ locale: 'en' });
        assert.equal(formatPercent(12.5), '12.5%');
        assert.equal(formatDelta(3.25), '+3.3%');
        assert.equal(formatPercent(null), '-');
    } finally {
        applyFormatSettings({ locale: 'en', currency: 'USD', dateFormat: null });
    }
});

test('report dates honour the organization date format', async () => {
    const { applyFormatSettings } = await import('../../resources/js/lib/formatSettings.js');
    try {
        applyFormatSettings({ locale: 'en', dateFormat: 'Y-m-d' });
        assert.equal(formatDay('2026-08-18 00:00:00'), '2026-08-18');
        assert.equal(formatTimestampDate('2026-09-27 03:00:00'), '2026-09-26');
    } finally {
        applyFormatSettings({ locale: 'en', dateFormat: null });
    }
});
