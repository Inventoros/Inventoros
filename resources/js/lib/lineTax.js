// Per-line tax from tax rates, mirroring App\Support\LineTax on the server
// (which recomputes everything on save, so the forms only preview it).
//
//  - A line's net is its gross less its line discount.
//  - A document discount is shared across all lines in proportion to their
//    nets (in line order, keeping every cent), so each line's taxable amount
//    is its net less its share.
//  - Lines with a rate are grouped by rate: each rate's tax is rounded once
//    on the rate's total taxable amount, then split back across its lines.
//  - A line without a rate keeps the tax amount it carries.
//
// Money is in integer cents and rounds half away from zero, as on the server.
// Shared by sales orders and, later, purchase orders.

import { formatLocale } from './formatSettings.js';

/** A rate in percent as a number, or null when there is none ('' or null). */
export function rateValue(rate) {
    if (rate === null || rate === undefined || rate === '') return null;
    const value = Number.parseFloat(rate);
    return Number.isFinite(value) && value >= 0 ? value : null;
}

// Thousandths of a percent, the precision rates are stored at.
const rateMillis = (rate) => Math.round(rateValue(rate) * 1000);

// round(numerator / denominator), half away from zero, for non-negative
// BigInts.
const divRound = (numerator, denominator) => (numerator * 2n + denominator) / (denominator * 2n);

/** The tax for a rate on a taxable amount in cents, rounded to the cent. */
export function taxCents(baseCents, rate) {
    if (rateValue(rate) === null || baseCents <= 0) return 0;
    return Number(divRound(BigInt(baseCents) * BigInt(rateMillis(rate)), 100000n));
}

/** Split an amount in cents across weights (cents), keeping every cent. */
export function allocateCents(amountCents, weights) {
    const total = weights.reduce((sum, weight) => sum + BigInt(Math.max(0, weight)), 0n);
    let cumulative = 0n;
    let allocated = 0;

    return weights.map((weight) => {
        cumulative += BigInt(Math.max(0, weight));
        const target = total === 0n ? 0 : Number(divRound(BigInt(amountCents) * cumulative, total));
        const share = target - allocated;
        allocated = target;
        return share;
    });
}

/**
 * Each line's taxable amount and tax, in cents.
 *
 * @param {Array<{netCents: number, taxRate?: any, taxCents?: number}>} lines
 * @param {number} documentDiscountCents
 * @returns {Array<{taxableCents: number, taxCents: number, rate: number|null}>}
 */
export function computeLineTaxes(lines, documentDiscountCents = 0) {
    const nets = lines.map((line) => Math.max(0, line.netCents || 0));
    const merchandise = nets.reduce((sum, net) => sum + net, 0);
    const discount = Math.min(Math.max(0, documentDiscountCents || 0), merchandise);
    const taxables = allocateCents(merchandise - discount, nets);

    const result = lines.map((line, index) => {
        const rate = rateValue(line.taxRate);
        return {
            taxableCents: taxables[index],
            taxCents: rate === null ? Math.max(0, line.taxCents || 0) : 0,
            rate,
        };
    });

    const groups = new Map();
    result.forEach((line, index) => {
        if (line.rate === null) return;
        const key = rateMillis(line.rate);
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(index);
    });

    for (const indexes of groups.values()) {
        const weights = indexes.map((index) => result[index].taxableCents);
        const groupTax = taxCents(weights.reduce((sum, weight) => sum + weight, 0), result[indexes[0]].rate);
        allocateCents(groupTax, weights).forEach((share, position) => {
            result[indexes[position]].taxCents = share;
        });
    }

    return result;
}

/** Taxable amount and tax per rate, highest rate first (rated lines only). */
export function taxByRate(computed) {
    const byRate = new Map();
    for (const line of computed) {
        if (line.rate === null) continue;
        const key = rateMillis(line.rate);
        const entry = byRate.get(key) ?? { rate: line.rate, taxableCents: 0, taxCents: 0 };
        entry.taxableCents += line.taxableCents;
        entry.taxCents += line.taxCents;
        byRate.set(key, entry);
    }

    return [...byRate.entries()].sort((a, b) => b[0] - a[0]).map(([, entry]) => entry);
}

const cents = (value) => Math.round((Number.parseFloat(value) || 0) * 100);

/**
 * The tax-inclusive price for a net price and a rate, in currency units.
 * Null without a rate or a price.
 */
export function grossFromNet(net, rate) {
    if (rateValue(rate) === null || net === '' || net === null || net === undefined) return null;
    const netCents = cents(net);
    return (netCents + taxCents(netCents, rate)) / 100;
}

/**
 * The net price that gives a tax-inclusive price at a rate, rounded to the
 * cent, in currency units. Null without a rate or a price.
 */
export function netFromGross(gross, rate) {
    if (rateValue(rate) === null || gross === '' || gross === null || gross === undefined) return null;
    const grossCents = Math.max(0, cents(gross));
    return Number(divRound(BigInt(grossCents) * 100000n, 100000n + BigInt(rateMillis(rate)))) / 100;
}

/** A rate for display, in the active locale: 27 -> "27%", 5.5 -> "5.5%". */
export function formatTaxRate(rate) {
    const value = rateValue(rate);
    if (value === null) return '';
    return new Intl.NumberFormat(formatLocale(), { style: 'percent', maximumFractionDigits: 3 }).format(value / 100);
}
