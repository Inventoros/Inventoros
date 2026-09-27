// Small formatting helpers shared by the customer portal pages.

export function formatMoney(value, currency = 'USD') {
    const amount = Number(value ?? 0);
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'USD' }).format(amount);
    } catch {
        return `${amount.toFixed(2)} ${currency || ''}`.trim();
    }
}

export function formatDate(value) {
    if (!value) {
        return '-';
    }
    return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
