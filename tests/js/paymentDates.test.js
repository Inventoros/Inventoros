// Runs with Node's built-in test runner: `npm run test:js`.
//
// A payment is recorded on a day (the form sends YYYY-MM-DD, stored as UTC
// midnight). Formatting that as an instant with new Date() showed the day
// before for anyone west of UTC, so the order page must format paid_at as a
// calendar day through lib/dates.
process.env.TZ = 'America/Los_Angeles';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { formatCalendarDate } from '../../resources/js/lib/dates.js';

const page = readFileSync(join(import.meta.dirname, '..', '..', 'resources', 'js', 'Pages', 'Orders', 'Show.vue'), 'utf8');

test('a UTC-midnight payment date is that calendar day west of UTC', () => {
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    assert.equal(formatCalendarDate('2026-09-28T00:00:00+00:00', options, 'en-US'), 'Sep 28, 2026');
    assert.notEqual(new Date('2026-09-28T00:00:00+00:00').getDate(), 28);
});

test('the order page formats payment dates as calendar days', () => {
    const formatter = /const formatPaymentDate = \(date\) =>\s*formatCalendarDate\(/;
    assert.match(page, formatter);
    assert.match(page, /formatPaymentDate\(payment\.paid_at\)/);
    assert.doesNotMatch(page, /formatDateShort\(payment\.paid_at\)/);
});
