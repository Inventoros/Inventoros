// This calculation deliberately does not import dashboard queries or selectors.
import { readFileSync, writeFileSync } from 'node:fs';

const { products, orders } = JSON.parse(readFileSync(new URL('./fixture-records.json', import.meta.url)));
const month = new Date().toISOString().slice(0, 7);
const expected = {
    currency: 'USD',
    totalProducts: products.length,
    totalValue: products.filter(p => p.is_active && p.currency === 'USD')
        .reduce((sum, p) => sum + Math.round(Number(p.price) * 100) * p.stock, 0) / 100,
    lowStock: products.filter(p => p.min_stock > 0 && p.stock <= p.min_stock)
        .sort((a, b) => a.stock - b.stock)
        .map(p => ({ id: p.id, sku: p.sku, name: p.name, stock: p.stock, minimum: p.min_stock, shortfall: p.min_stock - p.stock })),
    totalOrders: orders.length,
    pendingOrders: orders.filter(o => o.status === 'pending').length,
    revenueThisMonth: orders.filter(o => o.currency === 'USD' && o.status !== 'cancelled' && o.order_date.startsWith(month))
        .reduce((sum, o) => sum + Math.round(Number(o.total) * 100), 0) / 100,
};
writeFileSync(new URL('./expected.json', import.meta.url), `${JSON.stringify(expected, null, 2)}\n`);
console.log(expected);
