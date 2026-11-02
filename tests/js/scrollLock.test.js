import { test } from 'node:test';
import assert from 'node:assert/strict';
import { lockPageScroll } from '../../resources/js/lib/scrollLock.js';

test('overlapping dialogs release independently and restore the original scroll style', () => {
    const originalDocument = globalThis.document;
    globalThis.document = { body: { style: { overflow: 'clip' } } };
    try {
        const releaseTable = lockPageScroll();
        const releaseAction = lockPageScroll();
        releaseTable();
        releaseTable(); // repeated cleanup must not release another owner
        assert.equal(document.body.style.overflow, 'hidden');
        releaseAction();
        assert.equal(document.body.style.overflow, 'clip');
    } finally {
        globalThis.document = originalDocument;
    }
});
