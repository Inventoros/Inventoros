// Finds user-facing English written straight into a Vue component instead of
// going through vue-i18n: template text, user-facing attributes, string
// literals in template expressions, and confirm()/alert() messages in the
// script. Used by tests/js/noHardcodedText.test.js.

import { parse } from 'vue/compiler-sfc';

// Words that read the same in every locale: product and brand names, codes
// and acronyms written in mixed case. ALL-CAPS tokens (SKU, CSV, USD) and
// tokens with digits, dots, slashes or @ (SKU-001, you@example.com,
// order.created, https://...) are allowed without being listed.
export const ALLOWED_WORDS = new Set([
    'Inventoros',
    'GitHub', 'EasyPost', 'Shippo', 'Stripe', 'PayPal', 'FedEx', 'Slack', 'Shopify', 'WooCommerce',
    'Google', 'Microsoft', 'Apple', 'Android', 'iOS', 'Docker', 'Laravel', 'Vue', 'Composer', 'npm',
    'cPanel', 'GraphQL', 'OAuth', 'MySQL', 'PostgreSQL', 'SQLite', 'Redis', 'Postmark', 'Mailgun',
    'Sendmail', 'Resend', 'Amazon', 'Scramble', 'Swagger', 'OpenAPI', 'Zapier', 'Markdown',
    'px', 'kg', 'lb', 'lbs', 'oz', 'cm', 'mm', 'in', 'KB', 'MB', 'GB',
]);

// Attributes whose static value is shown to the user.
export const TEXT_ATTRIBUTES = new Set([
    'placeholder', 'title', 'aria-label', 'alt', 'label', 'description', 'subtitle', 'hint', 'tooltip',
    'empty-text', 'emptyText', 'empty-title', 'emptyTitle', 'empty-description', 'emptyDescription',
    'confirm-text', 'confirmText', 'cancel-text', 'cancelText', 'confirm-label', 'confirmLabel',
    'message', 'heading', 'help', 'helper-text', 'helperText', 'text', 'caption', 'value-label',
]);

// Bound attributes whose expressions are never display text.
const IGNORED_BINDINGS = new Set(['class', 'style', 'key', 'is', 'ref', 'id', 'for', 'name', 'type', 'href', 'src', 'variant', 'size', 'as', 'method', 'tone', 'icon', 'autocomplete']);

const ELEMENT = 1;
const TEXT = 2;
const INTERPOLATION = 5;
const ATTRIBUTE = 6;
const DIRECTIVE = 7;

/** Whether a piece of text contains a word that needs translating. */
export function hasTranslatableWords(text) {
    const cleaned = String(text)
        .replace(/&[a-zA-Z]+;|&#\d+;/g, ' ')
        .split(/\s+/)
        // Sentence punctuation around a word is not part of it ("Search...").
        .map((token) => token.replace(/^[("'[]+|[.,:;!?)"'\]]+$/g, ''))
        // Codes, emails, URLs, keys, numbers with units: SKU-001, a@b.c,
        // order.created, {placeholder}, 2FA
        .filter((token) => !/[\d_/@\\#={}]|\w[.:]\w/.test(token))
        .join(' ')
        .split(/[^A-Za-z]+/)
        .filter((word) => word.length >= 2)
        .filter((word) => !ALLOWED_WORDS.has(word))
        // ALL-CAPS acronyms and codes (SKU, CSV, USD, RMA)
        .filter((word) => word !== word.toUpperCase());

    return cleaned.length > 0;
}

// String literals inside a JS expression (quotes and template-literal text),
// skipping the arguments of t(), $t(), te(), route() and friends.
function stringLiterals(expression) {
    const withoutKeys = expression.replace(/\b(?:\$?t|te|\$te|tm|rt|route|usePermissions|can|hasPermission|hasAnyPermission)\(\s*(['"`])(?:\\.|(?!\1).)*\1/g, '');
    const out = [];
    for (const m of withoutKeys.matchAll(/'((?:\\.|[^'\\])*)'|"((?:\\.|[^"\\])*)"|`((?:\\.|[^`\\])*)`/g)) {
        const raw = m[1] ?? m[2] ?? m[3].replace(/\$\{[^}]*\}/g, ' ');
        out.push(raw);
    }
    return out;
}

// A literal reads as display text when it starts like a sentence ("Active",
// "No items found") or is several words ending in punctuation. Class lists,
// route names, variants and keys are lower-case and do not match.
function looksLikeDisplayText(literal) {
    const text = literal.trim();
    if (!hasTranslatableWords(text)) return false;
    return /^[A-Z][a-z]/.test(text) || /^[A-Za-z]{2,}(?:\s+[A-Za-z]{2,})+.*[.!?:]$/.test(text);
}

function scriptOf(source) {
    return [...source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]).join('\n');
}

/**
 * @param {string} source a .vue file
 * @returns {{ line: number, kind: string, text: string }[]}
 */
export function findHardcodedText(source) {
    const { descriptor } = parse(source);
    const found = [];
    const add = (loc, kind, text) => found.push({ line: loc.start.line, kind, text: String(text).replace(/\s+/g, ' ').trim() });

    const visit = (node) => {
        if (node.type === TEXT) {
            if (hasTranslatableWords(node.content)) add(node.loc, 'text', node.content);
        } else if (node.type === INTERPOLATION) {
            for (const literal of stringLiterals(node.content.content)) {
                if (looksLikeDisplayText(literal)) add(node.loc, 'expression', literal);
            }
        }

        if (node.type === ELEMENT) {
            const raw = node.tag === 'code' || node.tag === 'pre' || node.tag === 'kbd';
            for (const prop of node.props) {
                if (prop.type === ATTRIBUTE && TEXT_ATTRIBUTES.has(prop.name) && prop.value && hasTranslatableWords(prop.value.content)) {
                    add(prop.loc, `attribute ${prop.name}`, prop.value.content);
                }
                if (prop.type === DIRECTIVE && prop.exp && prop.exp.content) {
                    const arg = prop.arg && prop.arg.content;
                    if (prop.name === 'bind' && IGNORED_BINDINGS.has(arg)) continue;
                    if (prop.name === 'for' || prop.name === 'model' || prop.name === 'slot') continue;
                    for (const literal of stringLiterals(prop.exp.content)) {
                        if (looksLikeDisplayText(literal)) add(prop.loc, `expression ${prop.name}${arg ? `:${arg}` : ''}`, literal);
                    }
                }
            }
            // Code samples are shown as written.
            if (raw) return;
        }

        for (const child of node.children || []) visit(child);
        for (const branch of node.branches || []) visit(branch);
    };

    if (descriptor.template && descriptor.template.ast) {
        for (const child of descriptor.template.ast.children) visit(child);
    }

    // confirm('Delete this?') / alert('Saved') in the script.
    const script = scriptOf(source);
    const scriptStart = source.indexOf(script);
    for (const m of script.matchAll(/\b(?:window\.)?(confirm|alert|prompt)\(\s*(['"`])((?:\\.|(?!\2).)*)\2/g)) {
        if (hasTranslatableWords(m[3].replace(/\$\{[^}]*\}/g, ' '))) {
            const line = source.slice(0, scriptStart + m.index).split('\n').length;
            found.push({ line, kind: `script ${m[1]}()`, text: m[3] });
        }
    }

    return found;
}
