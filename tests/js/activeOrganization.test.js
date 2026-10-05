// Runs with Node's built-in test runner: `npm run test:js`.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    ORGANIZATION_HEADER,
    activeOrganization,
    applyActiveOrganization,
    withOrganizationHeader,
} from '../../resources/js/lib/activeOrganization.js';

test('the header carries the organization the page was rendered for', () => {
    applyActiveOrganization({ organization: { id: 7, name: 'Acme' } });

    assert.equal(activeOrganization(), 7);
    assert.deepEqual(withOrganizationHeader({ Accept: 'text/html' }), {
        Accept: 'text/html',
        [ORGANIZATION_HEADER]: '7',
    });
});

test('no organization means no header', () => {
    applyActiveOrganization({ organization: null });

    assert.equal(activeOrganization(), null);
    assert.deepEqual(withOrganizationHeader({ Accept: 'text/html' }), { Accept: 'text/html' });

    applyActiveOrganization(undefined);
    assert.deepEqual(withOrganizationHeader(), {});
});

test('a malformed id is ignored', () => {
    applyActiveOrganization({ organization: { id: '7' } });
    assert.equal(activeOrganization(), null);

    applyActiveOrganization({ organization: { id: 0 } });
    assert.equal(activeOrganization(), null);
});
