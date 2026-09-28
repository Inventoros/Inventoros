// Display labels for order status, source and approval status.
//
// These arrive as raw lowercase keys ("pending", "shopify", "not_required").
// Known values are translated; anything else (a plugin's own source) is
// humanized rather than shown raw.

import { humanizeKey } from './activityLabels.js';

export const ORDER_STATUSES = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
export const ORDER_SOURCES = ['manual', 'api', 'graphql', 'mcp', 'import', 'ebay', 'shopify', 'amazon', 'woocommerce'];
export const APPROVAL_STATUSES = ['not_required', 'pending', 'approved', 'rejected'];

const translated = (path, fallback, { t, te } = {}) => (t && te && te(path) ? t(path) : fallback);

export const orderStatusLabel = (status, i18n) =>
    status ? translated(`orders.status.${status}`, humanizeKey(status), i18n) : '';

export const orderSourceLabel = (source, i18n) =>
    source ? translated(`orders.sources.${source}`, humanizeKey(source), i18n) : '';

export const approvalStatusLabel = (status, i18n) =>
    status ? translated(`orders.approval.${status}`, humanizeKey(status), i18n) : '';

export const orderStatusVariant = (status) =>
    ({ pending: 'warning', processing: 'info', shipped: 'brand', delivered: 'success', cancelled: 'danger' }[status] || 'neutral');

export const approvalStatusVariant = (status) =>
    ({ pending: 'warning', approved: 'success', rejected: 'danger' }[status] || 'neutral');
