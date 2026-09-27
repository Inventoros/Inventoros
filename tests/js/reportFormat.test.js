// `npm run test:js`, pinned west of UTC like dates.test.js.
process.env.TZ = 'America/Los_Angeles';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatDay, formatTimestampDate } from '../../resources/js/lib/reportFormat.js';

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
