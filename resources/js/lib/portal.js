// Small formatting helpers shared by the customer portal pages.

import { formatCalendarDate, formatInstantDate } from './dates.js';

export function formatMoney(value, currency = 'USD') {
    const amount = Number(value ?? 0);
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'USD' }).format(amount);
    } catch {
        return `${amount.toFixed(2)} ${currency || ''}`.trim();
    }
}

const DATE_FORMAT = { year: 'numeric', month: 'short', day: 'numeric' };

/**
 * A calendar-date field (order_date, a payment's paid_at): the day the server
 * wrote. It arrives as UTC midnight, so parsing it as an instant showed the
 * day before to every customer west of UTC.
 */
export function formatDay(value) {
    return formatCalendarDate(value, DATE_FORMAT);
}

/**
 * A real instant (created_at, shipped_at, delivered_at), shown as the
 * viewer's local day.
 */
export function formatDate(value) {
    return formatInstantDate(value, DATE_FORMAT);
}
