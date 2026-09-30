// The settings every formatter in lib/money and lib/dates follows: the active
// UI locale, and the organization's regional settings (the shared `regional`
// Inertia prop). app.js and the i18n module keep them current, so pages call
// formatMoney(amount, currency) or displayCalendarDate(value) and get the
// viewer's language and the organization's currency and date format without
// threading them through every component.
//
// Plain module state, no Vue: the formatters stay importable from Node tests.

const state = {
    locale: 'en',
    currency: 'USD',
    dateFormat: null,
};

/**
 * Update the settings. Keys that are absent keep their current value.
 *
 * @param {{ locale?: string, currency?: string|null, dateFormat?: string|null }} settings
 */
export function applyFormatSettings(settings = {}) {
    if ('locale' in settings) state.locale = settings.locale || 'en';
    if ('currency' in settings) state.currency = String(settings.currency || 'USD').toUpperCase();
    if ('dateFormat' in settings) state.dateFormat = settings.dateFormat || null;
}

/**
 * Apply the shared `regional` prop ({ currency, date_format, time_format }).
 *
 * @param {{ currency?: string, date_format?: string|null }|null|undefined} regional
 */
export function applyRegionalProp(regional) {
    if (!regional) return;
    applyFormatSettings({ currency: regional.currency, dateFormat: regional.date_format });
}

/** The active UI locale (English, the fallback locale, until the app sets it). */
export const formatLocale = () => state.locale;

/** The organization's currency, used when a record carries none. */
export const defaultCurrency = () => state.currency;

/** The organization's PHP-style date format (e.g. "Y-m-d"), or null. */
export const orgDateFormat = () => state.dateFormat;
