// Stock figures for a product page.
//
// A product sold by variant keeps its units on the variants; products.stock
// is not updated when a variant moves. Its on-hand figure is the sum of the
// active variants' stock, as the server computes it (effective_stock).

const activeVariants = (variants) => (variants || []).filter((v) => v && v.is_active !== false);

const sellsByVariant = (product, variants) => Boolean(product?.has_variants) && (variants || []).length > 0;

const toNumber = (value) => {
    const n = Number.parseFloat(value);
    return Number.isFinite(n) ? n : 0;
};

export function onHandStock(product, variants = []) {
    if (product?.effective_stock !== undefined && product?.effective_stock !== null) {
        return Math.trunc(toNumber(product.effective_stock));
    }
    if (sellsByVariant(product, variants)) {
        return activeVariants(variants).reduce((sum, v) => sum + Math.trunc(toNumber(v.stock)), 0);
    }
    return Math.trunc(toNumber(product?.stock));
}

// Value of the on-hand stock at `price` or `purchase_price`: each active
// variant at its own amount, else the product's.
export function stockValue(product, variants = [], column = 'price') {
    if (sellsByVariant(product, variants)) {
        return activeVariants(variants).reduce(
            (sum, v) => sum + toNumber(v.stock) * toNumber(v[column] ?? product?.[column]),
            0,
        );
    }
    return onHandStock(product, variants) * toNumber(product?.[column]);
}

// 'out_of_stock' | 'low_stock' | 'in_stock'
export function stockStatus(product, variants = []) {
    const onHand = onHandStock(product, variants);
    if (onHand <= 0) return 'out_of_stock';
    if (onHand <= toNumber(product?.min_stock)) return 'low_stock';
    return 'in_stock';
}
