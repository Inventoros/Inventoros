// Defaults for a new purchase order line.
//
// A product linked to the PO's supplier carries that supplier's quoted cost
// and SKU (product.supplier_costs, from the product_supplier pivot). Those win
// over the product's generic purchase price: the PO is priced in what this
// supplier actually charges, and the supplier's own part number goes on the
// line so it prints on the PO they receive.

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

/**
 * Unit cost to prefill: the supplier's linked cost, else the variant's
 * purchase price, else the product's purchase price, else its sale price.
 */
export function defaultUnitCost(product, variant = null, supplierId = null) {
    if (!product) return 0;

    const linked = toNumber(supplierLink(product, supplierId)?.cost_price);
    if (linked !== null) return linked;

    return toNumber(variant?.purchase_price)
        ?? toNumber(product.purchase_price)
        ?? toNumber(product.price)
        ?? 0;
}

/**
 * The supplier's own SKU for this product, or '' when there is no link.
 */
export function defaultSupplierSku(product, supplierId = null) {
    return supplierLink(product, supplierId)?.supplier_sku || '';
}
