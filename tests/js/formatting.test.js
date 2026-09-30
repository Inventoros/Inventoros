// Runs with Node's built-in test runner: `npm run test:js`.
//
// One formatter for money and one set of date helpers, both following the
// active UI locale and the organization's regional settings (the shared
// `regional` prop): its currency, and its PHP-style date format for values
// shown as a day. Pinned west of UTC, where calendar days used to slip.
process.env.TZ = 'America/Los_Angeles';

import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { applyFormatSettings, formatLocale, defaultCurrency, orgDateFormat } from '../../resources/js/lib/formatSettings.js';
import { formatMoney, formatCompactMoney, formatNumber } from '../../resources/js/lib/money.js';
import { formatPhpDate, displayCalendarDate, displayDate, displayDateTime } from '../../resources/js/lib/dates.js';

// Intl separates French thousands with a narrow no-break space and puts a
// no-break space before the symbol; normalise both for readable assertions.
const plain = (s) => s.replace(/[  ]/g, ' ');

beforeEach(() => applyFormatSettings({ locale: 'en', currency: 'USD', dateFormat: null }));

test('settings are read from the shared regional prop and the UI locale', () => {
    applyFormatSettings({ locale: 'de', currency: 'eur', dateFormat: 'd.m.Y' });
    assert.equal(formatLocale(), 'de');
    assert.equal(defaultCurrency(), 'EUR');
    assert.equal(orgDateFormat(), 'd.m.Y');

    // Missing pieces keep their previous value; a blank currency falls back.
    applyFormatSettings({ locale: 'fr' });
    assert.equal(defaultCurrency(), 'EUR');
    applyFormatSettings({ currency: '' });
    assert.equal(defaultCurrency(), 'USD');
});

test('money follows the active locale: French and German number formats', () => {
    applyFormatSettings({ locale: 'fr' });
    assert.equal(plain(formatMoney(1234.5, 'EUR')), '1 234,50 €');
    applyFormatSettings({ locale: 'de' });
    assert.equal(plain(formatMoney(1234.5, 'EUR')), '1.234,50 €');
    assert.equal(plain(formatMoney(1234.5, 'USD')), '1.234,50 $');
    applyFormatSettings({ locale: 'en' });
    assert.equal(formatMoney(1234.5, 'EUR'), '€1,234.50');
});

test('money defaults to the organization currency when the record has none', () => {
    applyFormatSettings({ locale: 'en', currency: 'CAD' });
    assert.equal(formatMoney(20), 'CA$20.00');
    assert.equal(formatMoney(20, null), 'CA$20.00');
    // The record's own currency wins.
    assert.equal(formatMoney(20, 'USD'), '$20.00');
});

test('an explicit locale still wins over the active one', () => {
    applyFormatSettings({ locale: 'de' });
    assert.equal(formatMoney(1234.5, 'USD', 'en-US'), '$1,234.50');
});

test('compact money and plain numbers follow the locale too', () => {
    applyFormatSettings({ locale: 'de' });
    // German has no short form for thousands (ICU versions differ on the
    // trailing ",0"); millions are "Mio.".
    assert.match(plain(formatCompactMoney(234100, 'EUR')), /^234\.100(,0)? €$/);
    assert.equal(plain(formatCompactMoney(2500000, 'EUR')), '2,5 Mio. €');
    assert.equal(formatNumber(1234567), '1.234.567');
    applyFormatSettings({ locale: 'fr' });
    assert.equal(plain(formatNumber(1234567)), '1 234 567');
    applyFormatSettings({ locale: 'en' });
    assert.equal(formatNumber(1234567), '1,234,567');
    assert.equal(formatNumber('not a number'), '0');
});

test('formatPhpDate maps PHP date tokens', () => {
    const day = new Date(2026, 7, 7);
    assert.equal(formatPhpDate(day, 'Y-m-d'), '2026-08-07');
    assert.equal(formatPhpDate(day, 'd.m.Y'), '07.08.2026');
    assert.equal(formatPhpDate(day, 'm/d/Y'), '08/07/2026');
    assert.equal(formatPhpDate(day, 'd/m/y'), '07/08/26');
    assert.equal(formatPhpDate(day, 'j.n.Y'), '7.8.2026');
    assert.equal(formatPhpDate(day, 'F j, Y', 'en'), 'August 7, 2026');
    assert.equal(formatPhpDate(day, 'M j, Y', 'en'), 'Aug 7, 2026');
    assert.equal(formatPhpDate(day, 'D, d M Y', 'en'), 'Fri, 07 Aug 2026');
    assert.equal(formatPhpDate(day, 'l', 'en'), 'Friday');
    // A backslash escapes a literal character.
    assert.equal(formatPhpDate(day, 'Y \\w\\k W', 'en'), '2026 wk W');
});

test('month and weekday names follow the UI locale', () => {
    const day = new Date(2026, 7, 7);
    applyFormatSettings({ locale: 'fr' });
    assert.equal(formatPhpDate(day, 'j F Y'), '7 août 2026');
    applyFormatSettings({ locale: 'de' });
    assert.equal(formatPhpDate(day, 'l, j. F Y'), 'Freitag, 7. August 2026');
});

test('calendar dates honour the organization date format, on the day the server wrote', () => {
    applyFormatSettings({ locale: 'en', dateFormat: 'd.m.Y' });
    assert.equal(displayCalendarDate('2026-08-17'), '17.08.2026');
    // A date cast serialized at UTC midnight is still the 17th west of UTC.
    assert.equal(displayCalendarDate('2026-08-17T00:00:00.000000Z'), '17.08.2026');
    applyFormatSettings({ dateFormat: 'Y-m-d' });
    assert.equal(displayCalendarDate('2026-08-17'), '2026-08-17');
    assert.equal(displayCalendarDate(null), '-');
});

test('timestamps shown as a day use the viewer\'s day in the organization format', () => {
    applyFormatSettings({ locale: 'en', dateFormat: 'Y-m-d' });
    // 03:00 UTC on the 17th is the evening of the 16th in Los Angeles.
    assert.equal(displayDate('2026-08-17T03:00:00Z'), '2026-08-16');
    assert.equal(displayDate(''), '-');
});

test('without an organization date format, days are locale-aware', () => {
    applyFormatSettings({ locale: 'en', dateFormat: null });
    assert.equal(displayCalendarDate('2026-08-17'), 'Aug 17, 2026');
    applyFormatSettings({ locale: 'de' });
    assert.equal(displayCalendarDate('2026-08-17'), '17.08.2026');
    applyFormatSettings({ locale: 'fr' });
    assert.equal(displayCalendarDate('2026-08-17'), '17 août 2026');
    applyFormatSettings({ locale: 'ja' });
    assert.equal(displayCalendarDate('2026-08-17'), '2026/08/17');
});

test('timestamps with a time are locale-aware in the viewer\'s timezone', () => {
    applyFormatSettings({ locale: 'en', dateFormat: 'd.m.Y' });
    assert.equal(plain(displayDateTime('2026-08-17T17:30:00Z')), 'Aug 17, 2026, 10:30 AM');
    applyFormatSettings({ locale: 'de' });
    assert.equal(displayDateTime('2026-08-17T17:30:00Z'), '17.08.2026, 10:30');
    applyFormatSettings({ locale: 'fr' });
    assert.equal(plain(displayDateTime('2026-08-17T17:30:00Z')), '17 août 2026, 10:30');
    assert.equal(displayDateTime(null), '-');
});
