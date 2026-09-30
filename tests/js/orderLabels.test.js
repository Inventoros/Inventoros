// Runs with Node's built-in test runner: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    APPROVAL_STATUSES,
    ORDER_SOURCES,
    ORDER_STATUSES,
    approvalStatusLabel,
    orderSourceLabel,
    orderStatusLabel,
} from '../../resources/js/lib/orderLabels.js';

const en = JSON.parse(readFileSync(join(import.meta.dirname, '..', '..', 'resources', 'js', 'i18n', 'locales', 'en.json'), 'utf8'));
const lookup = (path) => path.split('.').reduce((node, key) => (node == null ? undefined : node[key]), en);
const i18n = { te: (path) => typeof lookup(path) === 'string', t: (path) => lookup(path) };

test('every status, source and approval state has an English label', () => {
    assert.deepEqual(ORDER_STATUSES.filter((s) => !i18n.te(`orders.status.${s}`)), []);
    assert.deepEqual(ORDER_SOURCES.filter((s) => !i18n.te(`orders.sources.${s}`)), []);
    assert.deepEqual(APPROVAL_STATUSES.filter((s) => !i18n.te(`orders.approval.${s}`)), []);
});

test('labels are capitalised words, not raw keys', () => {
    assert.equal(orderStatusLabel('pending', i18n), 'Pending');
    assert.equal(orderSourceLabel('graphql', i18n), 'GraphQL');
    assert.equal(approvalStatusLabel('not_required', i18n), lookup('orders.approval.not_required'));
    // Unknown values are humanized.
    assert.equal(orderSourceLabel('etsy_shop'), 'Etsy shop');
    assert.equal(orderStatusLabel(''), '');
});

test('the approval badge is labelled, so it cannot be mistaken for the order status', () => {
    const pending = approvalStatusLabel('pending', i18n);
    assert.notEqual(pending, orderStatusLabel('pending', i18n));
    assert.match(pending.toLowerCase(), /approval/);
});
