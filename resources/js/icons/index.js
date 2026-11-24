import { defineComponent, h } from 'vue';

/*
 * Inventoros icon set.
 *
 * Drawn for Inventoros on one 24px grid: 1.75 stroke, round caps and joins.
 * Corners vary with the shape: most rectangles use a 2px radius, the framed
 * tiles (Customers, Stock adjustments) 4px, and a few outlines a 1px bend.
 * Some icons carry a small solid dot, after the dot of the "i" in the logo:
 * Inventory's box, the Orders bag, a tag's hole (Categories), a reserved
 * bookmark (Reservations) and a role's key (Roles). The rest, the Returns
 * parcel included, have none.
 *
 * Each export is a component with the same props as the Lucide icons used
 * elsewhere (`size`, `stroke-width`, and `absolute-stroke-width`, accepted
 * and ignored), so it can replace one in place:
 * <component :is="item.icon" :size="16" :stroke-width="1.65" />.
 * Icons are decorative (aria-hidden) unless given an aria-label,
 * aria-labelledby or title.
 */

const dot = (cx, cy, r = 1.25) => ['circle', { cx, cy, r, fill: 'currentColor', stroke: 'none' }];
const path = (d) => ['path', { d }];
const rect = (x, y, width, height, rx = 2) => ['rect', { x, y, width, height, rx }];
const circle = (cx, cy, r) => ['circle', { cx, cy, r }];

function icon(name, nodes) {
    return defineComponent({
        name,
        props: {
            size: { type: [Number, String], default: 24 },
            strokeWidth: { type: [Number, String], default: 1.75 },
            // Lucide's prop, accepted so it does not fall through onto the
            // <svg>; these strokes are already drawn for their size.
            absoluteStrokeWidth: { type: Boolean, default: false },
        },
        setup(props, { attrs }) {
            const labelled = () => ['aria-label', 'ariaLabel', 'aria-labelledby', 'ariaLabelledby', 'title'].some((key) => attrs[key] != null && attrs[key] !== '');
            return () =>
                h(
                    'svg',
                    {
                        xmlns: 'http://www.w3.org/2000/svg',
                        width: props.size,
                        height: props.size,
                        viewBox: '0 0 24 24',
                        fill: 'none',
                        stroke: 'currentColor',
                        'stroke-width': props.strokeWidth,
                        'stroke-linecap': 'round',
                        'stroke-linejoin': 'round',
                        'aria-hidden': labelled() ? undefined : 'true',
                        class: 'inv-icon',
                    },
                    nodes.map(([tag, attrs]) => h(tag, attrs)),
                );
        },
    });
}

// Workspace
export const IconDashboard = icon('IconDashboard', [rect(3, 3, 8, 10), rect(13, 3, 8, 6), rect(13, 11, 8, 10), rect(3, 15, 8, 6)]);
export const IconInventory = icon('IconInventory', [
    path('M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5z'),
    path('M3.5 7.5 12 12l8.5-4.5'),
    path('M12 12v9'),
    dot(16.4, 13.6),
]);
export const IconOrders = icon('IconOrders', [
    path('M5 8h14l-1.15 11.2a2 2 0 0 1-2 1.8H8.15a2 2 0 0 1-2-1.8z'),
    path('M9 8V6.5a3 3 0 0 1 6 0V8'),
    dot(12, 14.25),
]);
export const IconCustomers = icon('IconCustomers', [rect(3, 3, 18, 18, 4), circle(12, 10, 3), path('M7 18a5 5 0 0 1 10 0')]);
// A parcel with an arrow bringing it back.
export const IconReturns = icon('IconReturns', [rect(3, 11, 12, 10), path('M3 15h12'), path('M20 15V9a4 4 0 0 0-4-4H9'), path('M12 2 9 5l3 3')]);
export const IconPurchaseOrders = icon('IconPurchaseOrders', [
    rect(5, 4, 14, 17),
    path('M9 4V3h6v1'),
    path('M9 10h6M9 14h6M9 18h3'),
]);
export const IconSuppliers = icon('IconSuppliers', [
    path('M3 21h18'),
    path('M4 21V11l5 3v-3l5 3V5h6v16'),
    path('M17 9v1.5M17 14v1.5'),
]);
export const IconApprovals = icon('IconApprovals', [circle(12, 12, 9), path('m8 12.5 2.75 2.75L16.25 9.5')]);

// Catalog
export const IconCategories = icon('IconCategories', [path('M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z'), dot(7.5, 7.5, 1.35)]);
export const IconLocations = icon('IconLocations', [
    path('M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z'),
    circle(12, 9.5, 2.5),
]);
export const IconWarehouses = icon('IconWarehouses', [path('M3 21V9l9-5 9 5v12'), path('M7 21v-8h10v8'), path('M7 17h10')]);

// Stock
export const IconStockAdjustments = icon('IconStockAdjustments', [rect(3, 3, 18, 18, 4), path('M7.5 9.5h5M10 7v5'), path('M11.5 16.5h5')]);
export const IconStockByWarehouse = icon('IconStockByWarehouse', [
    path('M3 21V3M21 21V3'),
    path('M3 12h18M3 21h18'),
    rect(6, 7, 4.5, 5, 1),
    rect(12.5, 16, 5, 5, 1),
]);
export const IconStockTransfers = icon('IconStockTransfers', [path('M4 8h13l-3.5-3.5'), path('M20 16H7l3.5 3.5')]);
export const IconStockAudits = icon('IconStockAudits', [rect(5, 4, 14, 17), path('M9 4V3h6v1'), path('m9 13 2 2 4-4')]);
export const IconWorkOrders = icon('IconWorkOrders', [
    path('M14.5 3.5a5 5 0 0 0-4.9 6.4L3.8 15.7a2.1 2.1 0 0 0 3 3l5.8-5.8a5 5 0 0 0 6.4-4.9l-2.9 2.9-2.6-.6-.6-2.6z'),
]);
export const IconReservations = icon('IconReservations', [path('M6 3h12v18l-6-4-6 4z'), dot(12, 9, 1.35)]);

// Insights
export const IconImportExport = icon('IconImportExport', [path('M8 15V4M5 7l3-3 3 3'), path('M16 9v11M13 17l3 3 3-3')]);
export const IconReports = icon('IconReports', [
    path('M3 21h18'),
    rect(5, 11, 3.5, 7, 1),
    rect(10.25, 6, 3.5, 12, 1),
    rect(15.5, 9, 3.5, 9, 1),
]);
export const IconActivityLog = icon('IconActivityLog', [circle(12, 12, 9), path('M12 7v5l3 2')]);

// Administration
export const IconUsers = icon('IconUsers', [
    circle(9, 8, 3),
    path('M3 20a6 6 0 0 1 12 0'),
    path('M16 5a3 3 0 0 1 0 6'),
    path('M21 20a6 6 0 0 0-4-5.65'),
]);
export const IconRoles = icon('IconRoles', [path('M12 3 4 6v6c0 4.5 3.4 8 8 9 4.6-1 8-4.5 8-9V6z'), dot(12, 10.75, 1.5), path('M12 12.25V15')]);
export const IconPlugins = icon('IconPlugins', [path('M9 3v4M15 3v4'), path('M6 7h12v3a6 6 0 0 1-12 0z'), path('M12 16v5')]);
export const IconSettings = icon('IconSettings', [path('M4 7h9M17 7h3M4 17h4M12 17h8'), circle(15, 7, 2), circle(10, 17, 2)]);

export const appIcons = {
    IconDashboard,
    IconInventory,
    IconOrders,
    IconCustomers,
    IconReturns,
    IconPurchaseOrders,
    IconSuppliers,
    IconApprovals,
    IconCategories,
    IconLocations,
    IconWarehouses,
    IconStockAdjustments,
    IconStockByWarehouse,
    IconStockTransfers,
    IconStockAudits,
    IconWorkOrders,
    IconReservations,
    IconImportExport,
    IconReports,
    IconActivityLog,
    IconUsers,
    IconRoles,
    IconPlugins,
    IconSettings,
};
