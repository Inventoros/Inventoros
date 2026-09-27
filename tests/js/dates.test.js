// Runs with Node's built-in test runner: `npm run test:js`.
//
// Pinned to a timezone west of UTC, where the bug lived: `new Date('2026-08-17')`
// is UTC midnight, which is the evening of Aug 16 in Los Angeles, so every
// date-only field rendered one day early.
process.env.TZ = 'America/Los_Angeles';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    toCalendarDate,
    formatCalendarDate,
    formatDateValue,
    todayIsoDate,
    isBeforeToday,
    toInstant,
    formatInstantDate,
    toIsoDate,
} from '../../resources/js/lib/dates.js';

test('the test really runs west of UTC', () => {
    assert.ok(new Date(2026, 7, 17).getTimezoneOffset() > 0);
    // The naive parse is the bug being fixed.
    assert.equal(new Date('2026-08-17').getDate(), 16);
});

test('toCalendarDate reads a date-only string as that local day', () => {
    const date = toCalendarDate('2026-08-17');
    assert.equal(date.getFullYear(), 2026);
    assert.equal(date.getMonth(), 7);
    assert.equal(date.getDate(), 17);
});

test('toCalendarDate keeps the calendar day of a serialized date or UTC-midnight timestamp', () => {
    // Laravel `date` casts and the order_date timestamp serialize like this.
    assert.equal(toCalendarDate('2026-08-17T00:00:00.000000Z').getDate(), 17);
    assert.equal(toCalendarDate('2026-08-17T00:00:00+00:00').getDate(), 17);
});

test('toCalendarDate returns null for empty or unparseable input', () => {
    assert.equal(toCalendarDate(null), null);
    assert.equal(toCalendarDate(''), null);
    assert.equal(toCalendarDate(undefined), null);
    assert.equal(toCalendarDate('not a date'), null);
});

test('formatCalendarDate formats the calendar day, with a dash when empty', () => {
    assert.equal(formatCalendarDate('2026-08-17T00:00:00+00:00', { year: 'numeric', month: 'short', day: 'numeric' }, 'en-US'), 'Aug 17, 2026');
    assert.equal(formatCalendarDate('2026-01-01', undefined, 'en-US'), '1/1/2026');
    assert.equal(formatCalendarDate(null), '-');
});

test('formatDateValue treats date-only strings as calendar days and full timestamps as instants', () => {
    assert.equal(formatDateValue('2026-08-17', undefined, 'en-US'), '8/17/2026');
    // 03:00 UTC on the 17th is still the 16th in Los Angeles: a real instant.
    assert.equal(formatDateValue('2026-08-17T03:00:00Z', undefined, 'en-US'), '8/16/2026');
    assert.equal(formatDateValue(null), '-');
});

test('todayIsoDate is the local calendar day, not the UTC one', () => {
    // 20:00 in Los Angeles on Sep 27 is already Sep 28 in UTC.
    const lateEvening = new Date(2026, 8, 27, 20, 0, 0);
    assert.equal(lateEvening.toISOString().slice(0, 10), '2026-09-28');
    assert.equal(todayIsoDate(lateEvening), '2026-09-27');
});

test('isBeforeToday compares calendar days', () => {
    const now = new Date(2026, 8, 27, 20, 0, 0);
    assert.equal(isBeforeToday('2026-09-27', now), false, 'expiring today is not yet expired');
    assert.equal(isBeforeToday('2026-09-26', now), true);
    assert.equal(isBeforeToday('2026-09-28T00:00:00.000000Z', now), false);
    assert.equal(isBeforeToday(null, now), false);
});

test('toInstant reads a zone-less server timestamp as UTC (the app timezone)', () => {
    // Raw query results (MAX(created_at), report builder rows) arrive like this.
    const instant = toInstant('2026-09-27 03:00:00');
    assert.equal(instant.toISOString(), '2026-09-27T03:00:00.000Z');
    assert.equal(toInstant('2026-09-27T03:00:00').toISOString(), '2026-09-27T03:00:00.000Z');
    assert.equal(toInstant('2026-09-27 03:00:00.123456').toISOString(), '2026-09-27T03:00:00.123Z');
});

test('toInstant keeps an explicit zone or offset', () => {
    assert.equal(toInstant('2026-09-27T03:00:00Z').toISOString(), '2026-09-27T03:00:00.000Z');
    assert.equal(toInstant('2026-09-27T03:00:00.000000Z').toISOString(), '2026-09-27T03:00:00.000Z');
    assert.equal(toInstant('2026-09-27T03:00:00+02:00').toISOString(), '2026-09-27T01:00:00.000Z');
});

test('toInstant returns null for empty or unparseable input', () => {
    assert.equal(toInstant(null), null);
    assert.equal(toInstant(''), null);
    assert.equal(toInstant('nope'), null);
});

test('formatInstantDate shows the viewer day for a UTC timestamp', () => {
    // 03:00 UTC on the 27th is the evening of the 26th in Los Angeles.
    assert.equal(formatInstantDate('2026-09-27 03:00:00', undefined, 'en-US'), '9/26/2026');
    assert.equal(formatInstantDate('2026-09-27T20:00:00Z', undefined, 'en-US'), '9/27/2026');
    assert.equal(formatInstantDate(null), '-');
});

test('formatDateValue handles zone-less report values: midnight is a calendar day, other times are UTC instants', () => {
    // A date column, or an order_date timestamp (stored at midnight).
    assert.equal(formatDateValue('2026-08-18 00:00:00', undefined, 'en-US'), '8/18/2026');
    // A created_at timestamp.
    assert.equal(formatDateValue('2026-09-27 03:00:00', undefined, 'en-US'), '9/26/2026');
});

test('toIsoDate gives the YYYY-MM-DD a date input needs, from any serialized calendar date', () => {
    assert.equal(toIsoDate('2026-08-18T00:00:00.000000Z'), '2026-08-18');
    assert.equal(toIsoDate('2026-08-18T00:00:00+00:00'), '2026-08-18');
    assert.equal(toIsoDate('2026-08-18'), '2026-08-18');
    assert.equal(toIsoDate(null), '');
});
