// Category shares describe the organization's currency only. Missing or
// negative valuations have no meaningful positive share bar.
export function categoryBreakdown(categories = [], total = 0) {
    const amount = Number(total);
    const canShowShares =
        Number.isFinite(amount) && amount > 0 && categories.every((row) => Number.isFinite(Number(row.value)) && Number(row.value) >= 0);

    return [...categories]
        .sort((a, b) => Number(b.value) - Number(a.value) || String(a.name || '').localeCompare(String(b.name || '')))
        .map((row) => ({ ...row, share: canShowShares ? Math.min(100, (Number(row.value) / amount) * 100) : null }));
}

export function stockLevel(product) {
    const stock = Number(product.effective_stock ?? product.stock ?? 0);
    const minimum = Math.max(0, Number(product.min_stock) || 0);
    return {
        stock,
        minimum,
        shortfall: Math.max(0, minimum - stock),
        // A zero minimum has no useful ratio. Negative stock still shows its
        // exact quantity, while the bar remains bounded by its zero baseline.
        percent: minimum > 0 ? Math.min(100, Math.max(0, (stock / minimum) * 100)) : 0,
    };
}
