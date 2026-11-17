import { computed } from 'vue';
import { computeLineTaxes, rateValue, taxByRate } from '../lib/lineTax.js';

/**
 * Live order totals for the order forms, mirroring OrderService on the server
 * (which recomputes everything on save, so this is a preview only).
 *
 * Tax base rule: a line discount comes off that line's gross; the order
 * discount comes off the merchandise net of line discounts; tax and shipping
 * are added after, never discounted. Money is handled in integer cents and
 * percentages round half up to the cent, as on the server.
 *
 * Tax is either per line or for the whole order, never both: once any line
 * has a tax rate the order tax is the sum of the line taxes (see lib/lineTax)
 * and the order-level tax field is not used; otherwise it is the order-level
 * tax plus any line tax amounts (lines imported with an amount).
 */
export const toCents = (value) => Math.round((parseFloat(value) || 0) * 100);

export const fromCents = (cents) => cents / 100;

export function discountCents(baseCents, type, value) {
    const amount = parseFloat(value);
    if (!type || !(amount > 0) || baseCents <= 0) return 0;

    if (type === 'percent') {
        return Math.round((baseCents * Math.min(amount, 100)) / 100);
    }

    return Math.min(toCents(amount), baseCents);
}

/**
 * The tax amount for a rate (in percent) of a taxable base, both in currency
 * units, rounded half up to the cent. Orders store tax as an amount; this
 * only fills that amount in. An empty or invalid rate gives 0, and a rate
 * over 100% is capped at 100%.
 */
export function taxFromRate(base, rate) {
    const baseCents = toCents(base);
    const percent = parseFloat(rate);
    if (!(percent > 0) || baseCents <= 0) return 0;

    return fromCents(Math.round((baseCents * Math.min(percent, 100)) / 100));
}

export function lineGrossCents(item) {
    return toCents(item.unit_price) * (parseInt(item.quantity, 10) || 0);
}

export function lineNetCents(item) {
    const gross = lineGrossCents(item);
    return gross - discountCents(gross, item.discount_type, item.discount_value);
}

/** Whether any of the lines has a tax rate. */
export function hasLineTaxRates(items) {
    return items.some((item) => rateValue(item.tax_rate) !== null);
}

export function useOrderTotals(form) {
    return computed(() => {
        const subtotal = form.items.reduce((sum, item) => sum + lineGrossCents(item), 0);
        const lineDiscounts = form.items.reduce(
            (sum, item) => sum + discountCents(lineGrossCents(item), item.discount_type, item.discount_value),
            0,
        );
        const net = subtotal - lineDiscounts;
        const orderDiscount = discountCents(net, form.discount_type, form.discount_value);
        const lineRates = hasLineTaxRates(form.items);
        const taxed = computeLineTaxes(
            form.items.map((item) => ({ netCents: lineNetCents(item), taxRate: item.tax_rate, taxCents: toCents(item.tax) })),
            orderDiscount,
        );
        const lineTax = taxed.reduce((sum, line) => sum + line.taxCents, 0);
        const tax = (lineRates ? 0 : toCents(form.tax)) + lineTax;
        const shipping = toCents(form.shipping);

        return {
            subtotal: fromCents(subtotal),
            lineDiscounts: fromCents(lineDiscounts),
            orderDiscount: fromCents(orderDiscount),
            // What an order-level tax rate applies to: merchandise after every discount.
            taxable: fromCents(net - orderDiscount),
            // True once any line has a tax rate: the order-level tax is not used.
            lineRates,
            // Per line, in form order: its tax (rate-computed or as entered).
            lineTaxes: taxed.map((line) => fromCents(line.taxCents)),
            // Per rate, highest first: [{ rate, taxable, tax }].
            taxByRate: taxByRate(taxed).map((entry) => ({
                rate: entry.rate,
                taxable: fromCents(entry.taxableCents),
                tax: fromCents(entry.taxCents),
            })),
            tax: fromCents(tax),
            shipping: fromCents(shipping),
            total: fromCents(net - orderDiscount + tax + shipping),
        };
    });
}
