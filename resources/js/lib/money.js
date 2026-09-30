// One money formatter for the app: the record's currency (the order's, else
// the organization's), with locale grouping and two decimals, so the same
// amount reads the same on the order page, its shipments and the portal.

export function formatMoney(value, currency = 'USD', locale = undefined) {
    const parsed = Number.parseFloat(value);
    const amount = Number.isFinite(parsed) ? parsed : 0;
    const code = String(currency || 'USD').toUpperCase();
    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency: code }).format(amount);
    } catch {
        // An unknown currency code: still show the amount and the code.
        return `${amount.toFixed(2)} ${code}`;
    }
}

// Compact form for stat tiles: $1.4K, CA$234.1K. Amounts under 1,000 are shown
// in full so small figures keep their cents.
export function formatCompactMoney(value, currency = 'USD', locale = undefined) {
    const parsed = Number.parseFloat(value);
    const amount = Number.isFinite(parsed) ? parsed : 0;
    if (Math.abs(amount) < 1000) return formatMoney(amount, currency, locale);
    const code = String(currency || 'USD').toUpperCase();
    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency: code, notation: 'compact', maximumFractionDigits: 1 }).format(amount);
    } catch {
        return formatMoney(amount, currency, locale);
    }
}
