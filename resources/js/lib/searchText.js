// Text matching for search boxes that filter in the browser (the product
// pickers on the order, purchase order and stock transfer forms, and the
// other client-side list filters).
//
// It mirrors App\Support\Search on the server: case-insensitive, apostrophes
// and quotes ignored on both sides (so "Players Handbook", "Player's Handbook"
// and "Player’s Handbook" all find each other, issue #430), and every other
// character matched literally.

// Typographic forms of the apostrophe and the double quote, ignored like the
// ASCII ones. Keep in step with Search::LOOKALIKES in app/Support/Search.php.
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

// Every character a search ignores, in the same order as Search::IGNORED.
export const IGNORED = `'"${Object.keys(LOOKALIKES).join('')}`;

const IGNORED_PATTERN = new RegExp(`[${IGNORED}]`, 'g');

/**
 * Lowercased, trimmed text with compatibility forms folded and apostrophes
 * and quotes removed, ready for substring comparison.
 */
export function normalizeSearchText(value) {
    return String(value ?? '')
        .normalize('NFKC')
        .replace(IGNORED_PATTERN, '')
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
