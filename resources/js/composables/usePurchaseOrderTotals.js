import { computed } from 'vue';
import { computeLineTaxes, rateValue, taxByRate } from '../lib/lineTax.js';

/**
 * Live purchase order totals for the PO forms, mirroring PurchaseOrderService
 * on the server (which recomputes everything on save, so this is a preview).
 *
 * A line's net is quantity x unit cost (unit costs are net, before tax; POs
 * have no discounts). Tax is either per line or for the whole PO, never both:
 * once any line has a tax rate the PO's tax is the sum of the line taxes (see
 * lib/lineTax) and the PO-level tax field is not used; otherwise it is the
 * PO-level tax plus any line tax amounts (lines sent with an amount over the
 * API). Shipping is never taxed. Money is in integer cents.
 */
const toCents = (value) => Math.round((Number.parseFloat(value) || 0) * 100);

const fromCents = (cents) => cents / 100;

/** A line's net in cents: quantity x unit cost. */
export function lineNetCents(item) {
    return toCents(item.unit_cost) * (Number.parseInt(item.quantity, 10) || 0);
}

/** Whether any of the lines has a tax rate. */
export function hasLineTaxRates(items) {
    return items.some((item) => rateValue(item.tax_rate) !== null);
}

/**
 * The totals for a PO form's state ({ items, tax, shipping }).
 */
export function purchaseOrderTotals(form) {
    const items = form.items ?? [];
    const nets = items.map(lineNetCents);
    const subtotal = nets.reduce((sum, net) => sum + net, 0);
    const lineRates = hasLineTaxRates(items);
    const taxed = computeLineTaxes(
        items.map((item, index) => ({ netCents: nets[index], taxRate: item.tax_rate, taxCents: toCents(item.tax) })),
    );
    const lineTax = taxed.reduce((sum, line) => sum + line.taxCents, 0);
    const tax = (lineRates ? 0 : toCents(form.tax)) + lineTax;
    const shipping = toCents(form.shipping);

    return {
        subtotal: fromCents(subtotal),
        // True once any line has a tax rate: the PO-level tax is not used.
        lineRates,
        // Per line, in form order.
        lineNets: nets.map(fromCents),
        lineTaxes: taxed.map((line) => fromCents(line.taxCents)),
        lineGross: taxed.map((line, index) => fromCents(nets[index] + line.taxCents)),
        // Per rate, highest first: [{ rate, taxable, tax }].
        taxByRate: taxByRate(taxed).map((entry) => ({
            rate: entry.rate,
            taxable: fromCents(entry.taxableCents),
            tax: fromCents(entry.taxCents),
        })),
        tax: fromCents(tax),
        shipping: fromCents(shipping),
        total: fromCents(subtotal + tax + shipping),
    };
}

export function usePurchaseOrderTotals(form) {
    return computed(() => purchaseOrderTotals(form));
}
