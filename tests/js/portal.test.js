// Runs with Node's built-in test runner: `npm run test:js`.
//
// Pinned west of UTC, where the portal showed an order placed on Sept 28 as
// Sept 27: the server sends order_date as UTC midnight and the portal parsed
// it as an instant.
process.env.TZ = 'America/Los_Angeles';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatDate, formatDay } from '../../resources/js/lib/portal.js';

test('formatDay shows the calendar day the server wrote', () => {
    assert.equal(formatDay('2026-09-28T00:00:00+00:00'), 'Sep 28, 2026');
    assert.equal(formatDay('2026-09-28'), 'Sep 28, 2026');
});

test('formatDay returns a dash when there is no date', () => {
    assert.equal(formatDay(null), '-');
    assert.equal(formatDay(''), '-');
});

test('formatDate still shifts a real instant into the viewer timezone', () => {
    // 03:00 UTC on Sept 28 is the evening of Sept 27 in Los Angeles.
    assert.equal(formatDate('2026-09-28T03:00:00+00:00'), 'Sep 27, 2026');
    assert.equal(formatDate(null), '-');
});

test('portal dates honour the organization date format and UI locale', async () => {
    const { applyFormatSettings } = await import('../../resources/js/lib/formatSettings.js');
    try {
        applyFormatSettings({ locale: 'en', dateFormat: 'd/m/Y' });
        assert.equal(formatDay('2026-09-28T00:00:00+00:00'), '28/09/2026');
        assert.equal(formatDate('2026-09-28T03:00:00+00:00'), '27/09/2026');
        applyFormatSettings({ locale: 'de', dateFormat: null });
        assert.equal(formatDay('2026-09-28'), '28.09.2026');
    } finally {
        applyFormatSettings({ locale: 'en', dateFormat: null });
    }
});
