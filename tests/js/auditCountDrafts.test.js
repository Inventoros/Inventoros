import { test } from 'node:test';
import assert from 'node:assert/strict';
import { AuditCountDraftStore, auditDraftKey, countDraftPayload } from '../../resources/js/lib/auditCountDrafts.js';

const revision = 'a'.repeat(64);
const item = { id: 7, count_revision: revision };
const key = auditDraftKey(1, 2, 3);
const storage = () => {
    const data = new Map();
    return { getItem: (key) => data.get(key) ?? null, setItem: (key, value) => data.set(key, value), removeItem: (key) => data.delete(key) };
};

test('count and notes survive a page reload without storing the product catalogue', () => {
    const disk = storage();
    const first = new AuditCountDraftStore(disk, key);
    first.remember({ ...item, product: { name: 'Private catalogue' } }, 0, 'Shelf checked');
    const restored = new AuditCountDraftStore(disk, key);
    assert.deepEqual(countDraftPayload(restored.drafts[7]), { counted_quantity: 0, notes: 'Shelf checked', expected_revision: revision });
    assert.equal(disk.getItem(key).includes('Private catalogue'), false);
});

test('drafts are isolated by user, organization and audit', () => {
    const disk = storage();
    new AuditCountDraftStore(disk, key).remember(item, 5, '');
    for (const other of [auditDraftKey(2, 2, 3), auditDraftKey(1, 3, 3), auditDraftKey(1, 2, 4)]) {
        assert.deepEqual(new AuditCountDraftStore(disk, other).drafts, {});
    }
    assert.equal(auditDraftKey(null, 2, 3), null);
    assert.equal(auditDraftKey(1, 2, -3), null);
});

test('editing a restored count preserves its original revision until explicitly reviewed', () => {
    const disk = storage();
    new AuditCountDraftStore(disk, key).remember(item, 5, '');
    const restored = new AuditCountDraftStore(disk, key);
    const newer = { ...item, count_revision: 'b'.repeat(64) };
    restored.remember(newer, 6, 'Updated draft');
    assert.equal(restored.drafts[7].expectedRevision, revision);
    restored.remember(newer, 6, 'Reviewed draft', newer.count_revision);
    assert.equal(restored.drafts[7].expectedRevision, newer.count_revision);
});

test('a delayed save acknowledgement cannot discard edits made while the request was in flight', () => {
    const store = new AuditCountDraftStore(storage(), key);
    store.remember(item, 5, '');
    const sent = { ...store.drafts[7] };
    store.remember(item, 6, 'Another unit');
    store.forget(7, sent);
    assert.equal(store.drafts[7].quantity, '6');
    store.forget(7, { ...store.drafts[7] });
    assert.deepEqual(store.drafts, {});
});

test('blocked or full storage keeps an in-memory draft and reports that it cannot survive refresh', () => {
    const disk = { getItem: () => null, setItem: () => { throw new Error('Quota exceeded'); } };
    const store = new AuditCountDraftStore(disk, key);
    store.remember(item, 9, 'Keep this page open');
    assert.equal(store.available, false);
    assert.equal(store.drafts[7].quantity, '9');
    const blocked = new AuditCountDraftStore(null, key);
    blocked.remember(item, 10, '');
    assert.equal(blocked.available, false);
    assert.equal(blocked.drafts[7].quantity, '10');
});

test('invalid quantities remain editable drafts but cannot be submitted', () => {
    const store = new AuditCountDraftStore(storage(), key);
    for (const value of ['', '-1', '1.5', '1e3', '2147483648', 'NaN']) {
        store.remember(item, value, '');
        assert.equal(store.drafts[7].quantity, value);
        assert.equal(countDraftPayload(store.drafts[7]), null);
    }
    store.remember(item, '2147483647', '');
    assert.equal(countDraftPayload(store.drafts[7]).counted_quantity, 2147483647);
});

test('malformed stored entries and unknown formats are never submitted', () => {
    const disk = storage();
    disk.setItem(key, JSON.stringify({ version: 1, drafts: [{ itemId: 7, quantity: '5', notes: '', expectedRevision: '<script>' }] }));
    assert.deepEqual(new AuditCountDraftStore(disk, key).drafts, {});
    disk.setItem(key, '{');
    assert.deepEqual(new AuditCountDraftStore(disk, key).drafts, {});
    disk.setItem(key, JSON.stringify({ version: 99, drafts: [] }));
    assert.deepEqual(new AuditCountDraftStore(disk, key).drafts, {});
});

test('discarding one draft and clearing all drafts remove only the current audit key', () => {
    const disk = storage();
    disk.setItem('unrelated', 'keep');
    const store = new AuditCountDraftStore(disk, key);
    store.remember(item, 4, '');
    store.remember({ ...item, id: 8 }, 5, '');
    store.forget(7);
    assert.deepEqual(Object.keys(new AuditCountDraftStore(disk, key).drafts), ['8']);
    store.clear();
    assert.equal(disk.getItem(key), null);
    assert.equal(disk.getItem('unrelated'), 'keep');
});
