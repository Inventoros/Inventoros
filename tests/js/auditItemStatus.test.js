// Runs with Node's built-in test runner: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { auditItemStatus } from '../../resources/js/lib/auditItemStatus.js';

// A completed audit listed the lines nobody counted as "Pending"; nothing
// is pending once the audit is closed.
test('an uncounted line on a completed audit is not counted, not pending', () => {
    assert.equal(auditItemStatus('pending', 'completed'), 'not_counted');
    assert.equal(auditItemStatus('pending', 'cancelled'), 'not_counted');
});

test('an open audit and counted lines keep their status', () => {
    assert.equal(auditItemStatus('pending', 'in_progress'), 'pending');
    assert.equal(auditItemStatus('counted', 'completed'), 'counted');
    assert.equal(auditItemStatus('adjusted', 'completed'), 'adjusted');
});
