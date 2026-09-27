/**
 * Date helpers for calendar-date fields (an order date, a PO expected date,
 * a batch expiry): values that name a day, not an instant.
 *
 * `new Date('2026-08-17')` parses a date-only string as UTC midnight, and the
 * server serializes date columns (and the order_date timestamp) at UTC
 * midnight too, so formatting either in the browser shows the previous day
 * for everyone west of UTC. These helpers read the YYYY-MM-DD the server
 * wrote and build that day in the viewer's local time instead.
 *
 * Real instants (created_at, shipped_at, paid_at) should keep using
 * `new Date(value)`; they are meant to shift into the viewer's timezone.
 */

const DATE_PREFIX = /^(\d{4})-(\d{2})-(\d{2})/;
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * The calendar day a value names, as a local-midnight Date, or null.
 *
 * @param {string|Date|null|undefined} value
 * @returns {Date|null}
 */
export function toCalendarDate(value) {
    if (!value) return null;

    if (value instanceof Date) {
        return Number.isNaN(value.getTime())
            ? null
            : new Date(value.getFullYear(), value.getMonth(), value.getDate());
    }

    const match = DATE_PREFIX.exec(String(value));
    if (!match) return null;

    return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
}

/**
 * Format a calendar-date field. Returns '-' when there is no date.
 *
 * @param {string|Date|null|undefined} value
 * @param {Intl.DateTimeFormatOptions} [options]
 * @param {string|string[]} [locale]
 */
export function formatCalendarDate(value, options = undefined, locale = undefined) {
    const date = toCalendarDate(value);

    return date ? date.toLocaleDateString(locale, options) : '-';
}

/**
 * Format a value that may be either a date-only string or a full timestamp
 * (report columns mix both): date-only strings as calendar days, anything
 * with a time as an instant.
 *
 * @param {string|Date|null|undefined} value
 * @param {Intl.DateTimeFormatOptions} [options]
 * @param {string|string[]} [locale]
 */
export function formatDateValue(value, options = undefined, locale = undefined) {
    if (!value) return '-';

    if (typeof value === 'string' && DATE_ONLY.test(value)) {
        return formatCalendarDate(value, options, locale);
    }

    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleDateString(locale, options);
}

/**
 * Today's local calendar day as YYYY-MM-DD, for date inputs.
 * (`new Date().toISOString().slice(0, 10)` is the UTC day, which is already
 * tomorrow in the evening west of UTC.)
 *
 * @param {Date} [now]
 */
export function todayIsoDate(now = new Date()) {
    const pad = (n) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/**
 * Whether a calendar-date field is a day before today (e.g. an expired
 * batch). A date of today is not before today.
 *
 * @param {string|Date|null|undefined} value
 * @param {Date} [now]
 */
export function isBeforeToday(value, now = new Date()) {
    const date = toCalendarDate(value);
    if (!date) return false;

    return date < toCalendarDate(now);
}
