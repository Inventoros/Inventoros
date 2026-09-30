// Small formatting helpers shared by the customer portal pages.

import { displayCalendarDate, displayDate } from './dates.js';

// Money uses the app-wide formatter so the portal matches staff pages.
export { formatMoney } from './money.js';

/**
 * A calendar-date field (order_date, a payment's paid_at): the day the server
 * wrote, in the organization's date format. It arrives as UTC midnight, so
 * parsing it as an instant showed the day before to every customer west of UTC.
 */
export function formatDay(value) {
    return displayCalendarDate(value);
}

/**
 * A real instant (created_at, shipped_at, delivered_at), shown as the
 * viewer's local day in the organization's date format.
 */
export function formatDate(value) {
    return displayDate(value);
}
