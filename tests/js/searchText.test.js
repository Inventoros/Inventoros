// Runs with Node's built-in test runner: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { LOOKALIKES, normalizeSearchText, textMatches } from '../../resources/js/lib/searchText.js';

test('typographic quotes fold to their ASCII forms', () => {
    assert.equal(normalizeSearchText('Player’s “Deluxe” ‘Box’'), 'player\'s "deluxe" \'box\'');
    assert.equal(normalizeSearchText('Playerʼs'), "player's");
    assert.equal(normalizeSearchText('  Crème Brûlée  '), 'crème brûlée');
    assert.equal(normalizeSearchText(null), '');
});

test("issue #430: \"Player's Handbook\" finds the product whichever apostrophe either side uses", () => {
    for (const stored of ["Player's Handbook", 'Player’s Handbook']) {
        for (const typed of ["Player's Handbook", 'Player’s Handbook', "player's handbook", 'Player', 'Handbook']) {
            assert.ok(textMatches(stored, typed), `${typed} should find ${stored}`);
        }
    }
    assert.equal(textMatches('Players Handbook', "Player's Handbook"), false);
});

test('other special characters are matched literally', () => {
    for (const [stored, typed] of [
        ['The “Deluxe” Box', 'The "Deluxe" Box'],
        ['Dungeons & Dragons', 'Dungeons & Dragons'],
        ['Cable <5m>', 'Cable <5m'],
        ['Path C:\\Games', 'C:\\Games'],
        ['100% Cotton', '100%'],
        ['a_b Widget', 'a_b'],
        ['Crème Brûlée Kit', 'crème brûlée'],
    ]) {
        assert.ok(textMatches(stored, typed), `${typed} should find ${stored}`);
    }
    assert.equal(textMatches('1000 Thread Count', '100%'), false);
    assert.equal(textMatches('axb Widget', 'a_b'), false);
    assert.equal(textMatches('Path C:Games', 'C:\\Games'), false);
});

test('every word of the term must appear, in any order, across any of the values', () => {
    assert.ok(textMatches(['Handbook', 'PHB-01'], 'phb handbook'));
    assert.equal(textMatches(['Handbook', 'PHB-01'], 'phb monster'), false);
    assert.ok(textMatches('Anything', '   '));
});

test('the lookalike list matches the server-side search', () => {
    const php = readFileSync(new URL('../../app/Support/Search.php', import.meta.url), 'utf8');
    const block = php.match(/LOOKALIKES = \[([\s\S]*?)\];/)[1];
    const server = Object.fromEntries([...block.matchAll(/"\\u\{([0-9A-F]+)\}" => (?:"(')"|'(")')/g)]
        .map(([, code, single, double]) => [String.fromCodePoint(Number.parseInt(code, 16)), single ?? double]));
    assert.deepEqual(LOOKALIKES, server);
});
