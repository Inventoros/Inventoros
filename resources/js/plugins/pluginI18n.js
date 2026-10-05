/**
 * Translations for runtime plugin bundles, on the app's own vue-i18n.
 *
 * A plugin's messages live under `plugins.{slug}` in every locale. The scoped
 * SDK can only add messages there and reads keys relative to it, so a plugin
 * cannot change the app's strings or another plugin's:
 *
 *     plugin.i18n.addMessages('en', { settings: { title: 'Cycle counts' } });
 *     plugin.i18n.t('settings.title');   // plugins.cycle-counts.settings.title
 *
 * Kept free of Vue imports so it can be unit tested with Node.
 */

const FORBIDDEN_KEYS = new Set(['__proto__', 'prototype', 'constructor']);

const isPlainObject = (value) =>
    value !== null && typeof value === 'object' && Object.getPrototypeOf(value) === Object.prototype;

/**
 * A copy of a plugin's message tree with only strings and nested plain
 * objects, and no prototype-polluting keys.
 */
export function sanitizeMessages(messages) {
    if (!isPlainObject(messages)) {
        throw new TypeError('Inventoros plugin SDK: messages must be a plain object.');
    }

    const copy = {};
    for (const [key, value] of Object.entries(messages)) {
        if (FORBIDDEN_KEYS.has(key)) {
            continue;
        }
        if (typeof value === 'string') {
            copy[key] = value;
        } else if (isPlainObject(value)) {
            copy[key] = sanitizeMessages(value);
        }
    }

    return copy;
}

function assertKey(key) {
    if (typeof key !== 'string' || key.trim() === '') {
        throw new TypeError('Inventoros plugin SDK: a translation key must be a non-empty string.');
    }
}

/**
 * Read-only access to the app's translations: { t, te, locale }.
 *
 * @param {object} composer The app's global vue-i18n composer (i18n.global).
 * @param {object} locale   A read-only ref of the app locale.
 */
export function createReadonlyI18n(composer, locale) {
    return Object.freeze({
        t: (key, params = {}) => {
            assertKey(key);
            return composer.t(key, params);
        },
        te: (key) => {
            assertKey(key);
            return composer.te(key) || composer.te(key, composer.fallbackLocale?.value ?? 'en');
        },
        locale,
    });
}

/**
 * The i18n member of a plugin's scoped SDK: { t, te, locale, addMessages }.
 *
 * @param {object} composer The app's global vue-i18n composer (i18n.global).
 * @param {string} slug     The plugin's slug.
 * @param {object} locale   A read-only ref of the app locale.
 */
export function createPluginI18n(composer, slug, locale) {
    const prefix = `plugins.${slug}.`;
    const global = createReadonlyI18n(composer, locale);

    return Object.freeze({
        t: (key, params = {}) => {
            assertKey(key);
            return global.t(prefix + key, params);
        },
        te: (key) => {
            assertKey(key);
            return global.te(prefix + key);
        },
        locale,
        addMessages(localeCode, messages) {
            if (typeof localeCode !== 'string' || localeCode.trim() === '') {
                throw new TypeError('Inventoros plugin SDK: addMessages needs a locale code such as "en".');
            }
            composer.mergeLocaleMessage(localeCode, { plugins: { [slug]: sanitizeMessages(messages) } });
        },
    });
}
