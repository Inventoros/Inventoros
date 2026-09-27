import { computed } from 'vue';

/**
 * Live order totals for the order forms, mirroring OrderService on the server
 * (which recomputes everything on save, so this is a preview only).
 *
 * Tax base rule: a line discount comes off that line's gross; the order
 * discount comes off the merchandise net of line discounts; tax and shipping
 * are added after, never discounted. Money is handled in integer cents and
 * percentages round half up to the cent, as on the server.
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

export function lineGrossCents(item) {
    return toCents(item.unit_price) * (parseInt(item.quantity, 10) || 0);
}

export function lineNetCents(item) {
    const gross = lineGrossCents(item);
    return gross - discountCents(gross, item.discount_type, item.discount_value);
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
        const tax = toCents(form.tax);
        const shipping = toCents(form.shipping);

        return {
            subtotal: fromCents(subtotal),
            lineDiscounts: fromCents(lineDiscounts),
            orderDiscount: fromCents(orderDiscount),
            tax: fromCents(tax),
            shipping: fromCents(shipping),
            total: fromCents(net - orderDiscount + tax + shipping),
        };
    });
}
