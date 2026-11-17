// Defaults for a new purchase order line.
//
// A product linked to the PO's supplier carries that supplier's quoted cost
// and SKU (product.supplier_costs, from the product_supplier pivot). Those win
// over the product's generic purchase price: the PO is priced in what this
// supplier actually charges, and the supplier's own part number goes on the
// line so it prints on the PO they receive.
//
// Amounts are only carried over in the PO's own currency: the link cost when
// the link is in it (cost_currency), the product's prices when the product
// is priced in it. A cost in another currency is not copied as if it were
// the PO's; the line starts at zero for the buyer to fill in.

const toNumber = (value) => {
    if (value === null || value === undefined || value === '') return null;
    const number = Number.parseFloat(value);
    return Number.isFinite(number) ? number : null;
};

/**
 * The product_supplier link for this supplier, or null.
 */
export function supplierLink(product, supplierId) {
    if (!product || supplierId === null || supplierId === undefined || supplierId === '') return null;
    const links = Array.isArray(product.supplier_costs) ? product.supplier_costs : [];
    return links.find((link) => String(link.supplier_id) === String(supplierId)) ?? null;
}

const sameCurrency = (a, b) => String(a || '').trim().toUpperCase() === String(b || '').trim().toUpperCase();

/**
 * Unit cost to prefill: the supplier's linked cost, else the variant's
 * purchase price, else the product's purchase price, else its sale price.
 *
 * With `currency` (the PO's), each amount is used only when it is in that
 * currency: the link's cost_currency, the product's currency. Without it
 * (or when the payload does not say), amounts are used as they are.
 */
export function defaultUnitCost(product, variant = null, supplierId = null, currency = null) {
    if (!product) return 0;

    const link = supplierLink(product, supplierId);
    const linked = toNumber(link?.cost_price);
    if (linked !== null && (!currency || !link.cost_currency || sameCurrency(link.cost_currency, currency))) {
        return linked;
    }

    if (currency && product.currency && !sameCurrency(product.currency, currency)) return 0;

    return toNumber(variant?.purchase_price)
        ?? toNumber(product.purchase_price)
        ?? toNumber(product.price)
        ?? 0;
}

/**
 * Tax rate to prefill: the product's own rate (variants share it), or ''
 * (no rate) when the product has none. 0 is a real rate.
 */
export function defaultTaxRate(product) {
    return toNumber(product?.tax_rate) ?? '';
}

/**
 * The supplier's own SKU for this product, or '' when there is no link.
 */
export function defaultSupplierSku(product, supplierId = null) {
    return supplierLink(product, supplierId)?.supplier_sku || '';
}
