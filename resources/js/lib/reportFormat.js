import { formatCalendarDate, formatDateValue, formatInstantDate } from './dates.js';
import { formatLocale } from './formatSettings.js';
import { formatMoney, formatNumber as formatPlainNumber } from './money.js';

// Shared number formatting for the report pages. Figures come from the
// server already rounded, so these only present them, in the active UI
// locale. Money goes through the app's one formatter (lib/money) in the
// currency given, the organization's when none is.

export const formatCurrency = (value, currency = undefined) => formatMoney(value, currency);

// "Plus EUR 10.00, USD 50.00": the totals in currencies other than `base`,
// listed beside a headline figure instead of being added into it.
export const otherCurrencyTotals = (values, base) =>
    (values || [])
        .filter((row) => row.currency !== base && Number(row.amount) !== 0)
        .map((row) => formatCurrency(row.amount, row.currency))
        .join(', ');

export const formatNumber = (value) => formatPlainNumber(value);

// Percentages arrive as 12.5 for 12.5%, one decimal.
const percent = (value, signDisplay) =>
    new Intl.NumberFormat(formatLocale(), {
        style: 'percent',
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
        signDisplay,
    }).format((Number(value) || 0) / 100);

export const formatPercent = (value) =>
    value === null || value === undefined ? '-' : percent(value, 'auto');

export const formatDelta = (value) =>
    value === null || value === undefined ? '-' : percent(value, 'exceptZero');

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
