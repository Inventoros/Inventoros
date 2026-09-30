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
 * Real instants (created_at, shipped_at, paid_at) are meant to shift into the
 * viewer's timezone. Eloquent serializes them with a zone, but raw query
 * results (MAX(created_at), report builder rows) arrive zone-less, as
 * `YYYY-MM-DD HH:MM:SS` in the app timezone (UTC); `new Date()` would read
 * those as local time. Use toInstant()/formatInstantDate() for them.
 */

import { formatLocale, orgDateFormat } from './formatSettings.js';

const DATE_PREFIX = /^(\d{4})-(\d{2})-(\d{2})/;
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;
// A date and time with no Z or offset: the server's own (UTC) wall time.
const ZONELESS_DATETIME = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)$/;
const ZONELESS_MIDNIGHT = /^\d{4}-\d{2}-\d{2}[ T]00:00(?::00(?:\.0+)?)?$/;

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
export function formatCalendarDate(value, options = undefined, locale = formatLocale()) {
    const date = toCalendarDate(value);

    return date ? date.toLocaleDateString(locale, options) : '-';
}

/**
 * The instant a timestamp names, or null. A zone-less server timestamp is
 * read as UTC (the app timezone); one with a Z or offset keeps it.
 *
 * @param {string|Date|null|undefined} value
 * @returns {Date|null}
 */
export function toInstant(value) {
    if (!value) return null;

    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    const text = String(value).trim();
    const zoneless = ZONELESS_DATETIME.exec(text);
    // JS Dates keep milliseconds; trim longer fractions (PHP sends micros).
    const date = zoneless
        ? new Date(`${zoneless[1]}T${zoneless[2].replace(/(\.\d{3})\d+$/, '$1')}Z`)
        : new Date(text);

    return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * Format the viewer's calendar day of a timestamp. Returns '-' when empty.
 *
 * @param {string|Date|null|undefined} value
 * @param {Intl.DateTimeFormatOptions} [options]
 * @param {string|string[]} [locale]
 */
export function formatInstantDate(value, options = undefined, locale = formatLocale()) {
    const date = toInstant(value);

    return date ? date.toLocaleDateString(locale, options) : '-';
}

/**
 * The YYYY-MM-DD a date input needs, from a serialized calendar date (the
 * day the server wrote, never shifted by the viewer's timezone).
 *
 * @param {string|null|undefined} value
 */
export function toIsoDate(value) {
    const match = value ? DATE_PREFIX.exec(String(value)) : null;

    return match ? `${match[1]}-${match[2]}-${match[3]}` : '';
}

/**
 * Whether a raw value names a calendar day: a date-only string, or a
 * zone-less server value at exactly midnight (how date columns and the
 * order_date timestamp come out of a raw query).
 *
 * @param {unknown} value
 */
export function isCalendarDayValue(value) {
    return typeof value === 'string' && (DATE_ONLY.test(value.trim()) || ZONELESS_MIDNIGHT.test(value.trim()));
}

/**
 * Format a value that may be either a calendar date or a timestamp (report
 * columns mix both):
 *  - a date-only string, or a zone-less server value at exactly midnight
 *    (how date columns and the order_date timestamp come out of a raw query),
 *    is a calendar day;
 *  - anything else is an instant (zone-less means UTC).
 *
 * @param {string|Date|null|undefined} value
 * @param {Intl.DateTimeFormatOptions} [options]
 * @param {string|string[]} [locale]
 */
export function formatDateValue(value, options = undefined, locale = formatLocale()) {
    if (!value) return '-';

    if (isCalendarDayValue(value)) {
        return formatCalendarDate(value, options, locale);
    }

    return formatInstantDate(value, options, locale);
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

// --- Display helpers: what pages call -------------------------------------
//
// A value shown as a day honours the organization's date format (Settings >
// Organization > Regional, a PHP-style pattern such as "Y-m-d" or "d.m.Y"),
// in the active UI locale for month and weekday names. With no format set it
// is the locale's own medium date ("Aug 17, 2026", "17.08.2026"). A value shown
// with its time is locale-aware.

const pad2 = (n) => String(n).padStart(2, '0');

/**
 * Format a Date with a PHP date() pattern. Supports the day, weekday, month
 * and year tokens (d D j l N w m M n F Y y); a backslash escapes a character
 * and anything else is copied as is.
 *
 * @param {Date} date
 * @param {string} pattern
 * @param {string|string[]} [locale]
 */
export function formatPhpDate(date, pattern, locale = formatLocale()) {
    const name = (options) => date.toLocaleDateString(locale, options);
    let out = '';

    for (let i = 0; i < pattern.length; i++) {
        const ch = pattern[i];
        if (ch === '\\') {
            out += pattern[++i] ?? '';
            continue;
        }
        switch (ch) {
            case 'd': out += pad2(date.getDate()); break;
            case 'j': out += String(date.getDate()); break;
            case 'D': out += name({ weekday: 'short' }); break;
            case 'l': out += name({ weekday: 'long' }); break;
            case 'N': out += String(date.getDay() || 7); break;
            case 'w': out += String(date.getDay()); break;
            case 'm': out += pad2(date.getMonth() + 1); break;
            case 'n': out += String(date.getMonth() + 1); break;
            case 'M': out += name({ month: 'short' }); break;
            case 'F': out += name({ month: 'long' }); break;
            case 'Y': out += String(date.getFullYear()); break;
            case 'y': out += pad2(date.getFullYear() % 100); break;
            default: out += ch;
        }
    }

    return out;
}

function displayDay(date) {
    if (!date) return '-';
    const pattern = orgDateFormat();

    return pattern ? formatPhpDate(date, pattern) : date.toLocaleDateString(formatLocale(), { dateStyle: 'medium' });
}

/**
 * A calendar-date field (order_date, expected_date, a payment's paid_at,
 * a batch expiry) in the organization's date format. '-' when empty.
 *
 * @param {string|Date|null|undefined} value
 */
export function displayCalendarDate(value) {
    return displayDay(toCalendarDate(value));
}

/**
 * A timestamp (created_at, shipped_at) shown as the viewer's day, in the
 * organization's date format. '-' when empty.
 *
 * @param {string|Date|null|undefined} value
 */
export function displayDate(value) {
    return displayDay(toInstant(value));
}

/**
 * A timestamp with its time, locale-aware, in the viewer's timezone.
 * '-' when empty.
 *
 * @param {string|Date|null|undefined} value
 */
export function displayDateTime(value) {
    const date = toInstant(value);

    return date ? date.toLocaleString(formatLocale(), { dateStyle: 'medium', timeStyle: 'short' }) : '-';
}
