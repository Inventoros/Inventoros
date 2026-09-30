// Runs with Node's built-in test runner: `npm run test:js`.
//
// Pinned west of UTC, where the portal showed an order placed on Sept 28 as
// Sept 27: the server sends order_date as UTC midnight and the portal parsed
// it as an instant.
process.env.TZ = 'America/Los_Angeles';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatDate, formatDay } from '../../resources/js/lib/portal.js';

const opts = { year: 'numeric', month: 'short', day: 'numeric' };

test('formatDay shows the calendar day the server wrote', () => {
    assert.equal(formatDay('2026-09-28T00:00:00+00:00'), new Date(2026, 8, 28).toLocaleDateString(undefined, opts));
    assert.equal(formatDay('2026-09-28'), new Date(2026, 8, 28).toLocaleDateString(undefined, opts));
});

test('formatDay returns a dash when there is no date', () => {
    assert.equal(formatDay(null), '-');
    assert.equal(formatDay(''), '-');
});

test('formatDate still shifts a real instant into the viewer timezone', () => {
    // 03:00 UTC on Sept 28 is the evening of Sept 27 in Los Angeles.
    assert.equal(formatDate('2026-09-28T03:00:00+00:00'), new Date(2026, 8, 27).toLocaleDateString(undefined, opts));
    assert.equal(formatDate(null), '-');
});
