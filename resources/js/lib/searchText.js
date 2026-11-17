// Text matching for search boxes that filter in the browser (the product
// pickers on the order, purchase order and stock transfer forms, and the
// other client-side list filters).
//
// It mirrors App\Support\Search on the server: case-insensitive, every
// character matched literally, and typographic quotes treated as their ASCII
// forms, so "Player's Handbook" finds "Player’s Handbook" and the other way
// round (issue #430).

// Keep in step with Search::LOOKALIKES in app/Support/Search.php.
export const LOOKALIKES = Object.freeze({
    '‘': "'", // left single quotation mark
    '’': "'", // right single quotation mark / typographic apostrophe
    '‛': "'", // single high-reversed-9 quotation mark
    'ʼ': "'", // modifier letter apostrophe
    '′': "'", // prime
    '＇': "'", // fullwidth apostrophe
    '“': '"', // left double quotation mark
    '”': '"', // right double quotation mark
    '„': '"', // double low-9 quotation mark
    '‟': '"', // double high-reversed-9 quotation mark
    '″': '"', // double prime
    '＂': '"', // fullwidth quotation mark
});

const LOOKALIKE_PATTERN = new RegExp(`[${Object.keys(LOOKALIKES).join('')}]`, 'g');

/**
 * Lowercased, trimmed text with compatibility forms and typographic quotes
 * folded, ready for substring comparison.
 */
export function normalizeSearchText(value) {
    return String(value ?? '')
        .replace(LOOKALIKE_PATTERN, (character) => LOOKALIKES[character])
        .normalize('NFKC')
        .toLocaleLowerCase()
        .trim();
}

/**
 * Whether every word of the term appears in the value (or in any of the
 * values, when given an array). An empty term matches everything.
 */
export function textMatches(values, term) {
    const words = normalizeSearchText(term).split(/\s+/).filter(Boolean);
    if (!words.length) return true;
    const text = normalizeSearchText((Array.isArray(values) ? values : [values]).filter((value) => value != null).join(' '));
    return words.every((word) => text.includes(word));
}
