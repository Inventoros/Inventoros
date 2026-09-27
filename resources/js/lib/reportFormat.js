import { formatCalendarDate, formatDateValue, formatInstantDate } from './dates.js';

// Shared number formatting for the report pages. Currency matches the
// sibling report pages (USD display); figures come from the server already
// rounded, so these only present them.

export const formatCurrency = (value) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(value) || 0);

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
