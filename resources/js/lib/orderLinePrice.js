// The unit price to prefill for an order line in the order's currency, the
// way OrderService prices a line sent without one:
//  - in the product's own currency, the variant's price, else the product's;
//  - in another currency, the product's price for that currency, unless the
//    line is a variant with a price of its own (that price is in the
//    product's currency only).
// null when there is no price in that currency: the user enters one.

const toNumber = (value) => {
    const n = Number.parseFloat(value);
    return Number.isFinite(n) ? n : null;
};

export function linePrice(product, variant, currency) {
    const code = String(currency || '').toUpperCase();
    const productCurrency = String(product?.currency || '').toUpperCase();

    if (!code || code === productCurrency) {
        return toNumber(variant?.price ?? product?.price);
    }

    if (variant?.own_price) return null;

    return toNumber(product?.prices?.[code]);
}
