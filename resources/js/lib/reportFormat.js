import { formatCalendarDate, formatDateValue, formatInstantDate } from './dates.js';
import { formatMoney } from './money.js';

// Shared number formatting for the report pages. Figures come from the
// server already rounded, so these only present them. Money goes through the
// app's one formatter (lib/money) in the currency given, USD when none is.

export const formatCurrency = (value, currency = 'USD') => formatMoney(value, currency, 'en-US');

// "Plus EUR 10.00, USD 50.00": the totals in currencies other than `base`,
// listed beside a headline figure instead of being added into it.
export const otherCurrencyTotals = (values, base) =>
    (values || [])
        .filter((row) => row.currency !== base && Number(row.amount) !== 0)
        .map((row) => formatCurrency(row.amount, row.currency))
        .join(', ');

export const formatNumber = (value) => new Intl.NumberFormat('en-US').format(Number(value) || 0);

export const formatPercent = (value) =>
    value === null || value === undefined ? '-' : `${Number(value).toFixed(1)}%`;

export const formatDelta = (value) => {
    if (value === null || value === undefined) return '-';
    const n = Number(value);
    return `${n > 0 ? '+' : ''}${n.toFixed(1)}%`;
};

export const deltaTone = (value) => {
    if (value === null || value === undefined || Number(value) === 0) return 'neutral';
    return Number(value) > 0 ? 'up' : 'down';
};

// Report rows come from raw queries, so dates arrive zone-less. Say which
// kind each field is rather than guessing (see lib/dates.js):
//  - formatDay: a calendar day (a date column, MAX(order_date));
//  - formatTimestampDate: the viewer's day of a UTC timestamp (MAX(created_at)).
export const formatDay = (value, locale = undefined) => formatCalendarDate(value, undefined, locale);

export const formatTimestampDate = (value, locale = undefined) => formatInstantDate(value, undefined, locale);

// A value of either kind (midnight = calendar day, other times = instant).
export const formatDate = (value, locale = undefined) => formatDateValue(value, undefined, locale);
