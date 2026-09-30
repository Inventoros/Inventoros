// Runs with Node's built-in test runner: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { resolveTheme } from '../../resources/js/lib/theme.js';

// With no saved choice the app was always dark; it now follows the system.
test('no saved choice follows the system preference', () => {
    assert.equal(resolveTheme(null, true), 'dark');
    assert.equal(resolveTheme(null, false), 'light');
    assert.equal(resolveTheme('', false), 'light');
});

test('a saved choice wins over the system', () => {
    assert.equal(resolveTheme('light', true), 'light');
    assert.equal(resolveTheme('dark', false), 'dark');
});

test('an unknown saved value is ignored', () => {
    assert.equal(resolveTheme('sepia', false), 'light');
});
