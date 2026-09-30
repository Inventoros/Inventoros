// Human labels for activity log actions and subject types.
//
// Actions are stored as raw keys ("payment_recorded", "portal.return_requested")
// and subjects as model class names ("App\Models\Purchasing\PurchaseOrder").
// Known ones are translated from admin.activityLog.actions / .subjects in the
// locale files; anything else (a plugin's own action, a new model) falls back
// to a humanized form rather than showing the raw key.

// Every action the core app records. The locale test checks en.json has a
// label for each, so a new action cannot ship showing its raw key.
export const ACTION_KEYS = [
    'created',
    'updated',
    'deleted',
    'viewed',
    'approval_requested',
    'approved',
    'rejected',
    'payment_recorded',
    'payment_refunded',
    'payment_voided',
    'invoice_emailed',
    'emailed',
    'auto_reorder',
    'quick_reorder',
    'return.lines_updated',
    'portal.return_requested',
    'pre_tracking_marked_paid',
];

// Model basenames that appear as activity subjects.
export const SUBJECT_KEYS = [
    'Customer',
    'CycleCountSchedule',
    'Order',
    'Product',
    'ProductCategory',
    'ProductLocation',
    'ProductVariant',
    'PurchaseOrder',
    'ReturnOrder',
    'Role',
    'Setting',
    'StockAdjustmentRequest',
    'StockAudit',
    'StockTransfer',
    'Supplier',
    'User',
    'Warehouse',
];

/** "portal.return_requested" -> "portal_return_requested" (i18n paths split on dots). */
export const actionMessageKey = (action) => String(action ?? '').replace(/\./g, '_');

/** "portal.return_requested" -> "Portal return requested". */
export function humanizeKey(key) {
    const words = String(key ?? '')
        .replace(/[._-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
    return words ? words.charAt(0).toUpperCase() + words.slice(1) : '';
}

/** "App\Models\Purchasing\PurchaseOrder" -> "PurchaseOrder". */
export const classBasename = (type) => String(type ?? '').split('\\').pop();

/** "PurchaseOrder" -> "Purchase order". */
export function humanizeClass(type) {
    const words = classBasename(type)
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/([A-Z])([A-Z][a-z])/g, '$1 $2')
        .toLowerCase();
    return words ? words.charAt(0).toUpperCase() + words.slice(1) : '';
}

/**
 * Label for an action. `t` / `te` are vue-i18n's translate / exists.
 * `overrides` maps raw keys to server-provided labels (security events).
 */
export function actionLabel(action, { t, te, overrides = {} } = {}) {
    if (!action) return '';
    if (overrides[action]) return overrides[action];
    const path = `admin.activityLog.actions.${actionMessageKey(action)}`;
    if (t && te && te(path)) return t(path);
    return humanizeKey(action);
}

/** Label for a subject type (a class name or basename). */
export function subjectLabel(type, { t, te } = {}) {
    if (!type) return '';
    const path = `admin.activityLog.subjects.${classBasename(type)}`;
    if (t && te && te(path)) return t(path);
    return humanizeClass(type);
}
