// Shared number formatting for the report pages. Currency matches the
// sibling report pages (USD display); figures come from the server already
// rounded, so these only present them.

export const formatCurrency = (value) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(value) || 0);

export const formatNumber = (value) => new Intl.NumberFormat('en-US').format(Number(value) || 0);

export const formatPercent = (value) =>
    value === null || value === undefined ? '-' : `${Number(value).toFixed(1)}%`;

export const formatDelta = (value) => {
    if (value === null || value === undefined) return '-';
    const n = Number(value);
    return `${n > 0 ? '+' : ''}${n.toFixed(1)}%`;
};

export const deltaTone = (value) => {
    if (value === null || value === undefined || Number(value) === 0) return 'neutral';
    return Number(value) > 0 ? 'up' : 'down';
};

export const formatDate = (value) => (value ? new Date(String(value).replace(' ', 'T')).toLocaleDateString() : '-');
