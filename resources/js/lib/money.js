// One money formatter for the app: the record's currency (the order's, else
// the organization's), in the active UI locale's number format, so the same
// amount reads the same on the order page, its shipments and the portal, and
// a French or German viewer sees "1 234,50 €" / "1.234,50 €".

import { defaultCurrency, formatLocale } from './formatSettings.js';

const currencyCode = (currency) => String(currency || defaultCurrency() || 'USD').toUpperCase();

export function formatMoney(value, currency = undefined, locale = formatLocale()) {
    const parsed = Number.parseFloat(value);
    const amount = Number.isFinite(parsed) ? parsed : 0;
    const code = currencyCode(currency);
    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency: code }).format(amount);
    } catch {
        // An unknown currency code: still show the amount and the code.
        return `${amount.toFixed(2)} ${code}`;
    }
}

// Compact form for stat tiles: $1.4K, CA$234.1K. Amounts under 1,000 are shown
// in full so small figures keep their cents.
export function formatCompactMoney(value, currency = undefined, locale = formatLocale()) {
    const parsed = Number.parseFloat(value);
    const amount = Number.isFinite(parsed) ? parsed : 0;
    if (Math.abs(amount) < 1000) return formatMoney(amount, currency, locale);
    const code = currencyCode(currency);
    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency: code, notation: 'compact', maximumFractionDigits: 1 }).format(amount);
    } catch {
        return formatMoney(amount, currency, locale);
    }
}

// A plain number (a quantity, a count) with the locale's grouping. Blank or
// invalid input formats as zero.
export function formatNumber(value, options = undefined, locale = formatLocale()) {
    const parsed = Number.parseFloat(value);
    const amount = Number.isFinite(parsed) ? parsed : 0;

    return new Intl.NumberFormat(locale, options).format(amount);
}
