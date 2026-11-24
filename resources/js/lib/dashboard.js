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

// Month to date against the same days of last month, as a percentage. Null
// when last month has nothing to compare with, so no trend is shown rather
// than an invented one.
export function periodChange(current, previous) {
    const now = Number(current);
    const before = Number(previous);
    if (!Number.isFinite(now) || !Number.isFinite(before) || before <= 0) return null;
    return ((now - before) / before) * 100;
}

// A product at or below zero, or under a quarter of its minimum, is urgent.
export function stockUrgency(level) {
    return level.stock <= 0 || (level.minimum > 0 && level.percent < 25) ? 'critical' : 'low';
}

// Daily movement totals for the chart: the largest day sets the scale, and
// the period totals feed the summary line.
export function movementSummary(series = []) {
    const days = series.map((day) => ({ ...day, in: Math.max(0, Number(day.in) || 0), out: Math.max(0, Number(day.out) || 0) }));
    return {
        days,
        totalIn: days.reduce((sum, day) => sum + day.in, 0),
        totalOut: days.reduce((sum, day) => sum + day.out, 0),
        peak: days.reduce((max, day) => Math.max(max, day.in, day.out), 0),
    };
}

// Two letters for a product with no photo: the first letters of its first
// two words, or the first two letters of a one-word name.
export function productInitials(name = '') {
    const words = String(name).trim().split(/\s+/).filter(Boolean);
    if (!words.length) return '?';
    const letters = words.length > 1 ? words[0][0] + words[1][0] : words[0].slice(0, 2);
    return letters.toUpperCase();
}
