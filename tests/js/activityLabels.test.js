// Runs with Node's built-in test runner: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    ACTION_KEYS,
    SUBJECT_KEYS,
    actionLabel,
    actionMessageKey,
    humanizeClass,
    humanizeKey,
    subjectLabel,
} from '../../resources/js/lib/activityLabels.js';

const en = JSON.parse(readFileSync(join(import.meta.dirname, '..', '..', 'resources', 'js', 'i18n', 'locales', 'en.json'), 'utf8'));
const lookup = (path) => path.split('.').reduce((node, key) => (node == null ? undefined : node[key]), en);
const te = (path) => typeof lookup(path) === 'string';
const t = (path) => lookup(path);

test('unknown keys are humanized, never shown raw', () => {
    assert.equal(humanizeKey('payment_recorded'), 'Payment recorded');
    assert.equal(humanizeKey('portal.return_requested'), 'Portal return requested');
    assert.equal(humanizeKey('Quick_reorder'), 'Quick reorder');
    assert.equal(humanizeClass('App\\Models\\Purchasing\\PurchaseOrder'), 'Purchase order');
    assert.equal(humanizeClass('StockAdjustmentRequest'), 'Stock adjustment request');
    assert.equal(actionLabel('some_plugin.thing_done'), 'Some plugin thing done');
    assert.equal(subjectLabel('Plugins\\Acme\\Models\\WidgetBatch'), 'Widget batch');
});

test('every core action and subject has an English label', () => {
    const missingActions = ACTION_KEYS.filter((key) => !te(`admin.activityLog.actions.${actionMessageKey(key)}`));
    const missingSubjects = SUBJECT_KEYS.filter((key) => !te(`admin.activityLog.subjects.${key}`));
    assert.deepEqual(missingActions, []);
    assert.deepEqual(missingSubjects, []);
});

test('labels come from the locale file, and server overrides win', () => {
    assert.equal(actionLabel('portal.return_requested', { t, te }), t('admin.activityLog.actions.portal_return_requested'));
    assert.equal(subjectLabel('App\\Models\\Order\\ReturnOrder', { t, te }), t('admin.activityLog.subjects.ReturnOrder'));
    assert.equal(actionLabel('auth.failed', { t, te, overrides: { 'auth.failed': 'Failed sign-in' } }), 'Failed sign-in');
});
