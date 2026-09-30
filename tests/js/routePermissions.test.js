// Runs with Node's built-in test runner: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { canVisit } from '../../resources/js/lib/routePermissions.js';

const staff = ['view_products', 'edit_products', 'view_orders', 'view_purchase_orders', 'receive_purchase_orders'];

test('a page is open when the user holds its permission', () => {
    assert.equal(canVisit('orders.index', staff), true);
    assert.equal(canVisit('products.edit', staff), true);
});

test('a page that would 403 is closed', () => {
    assert.equal(canVisit('orders.create', staff), false);
    assert.equal(canVisit('orders.edit', staff), false);
    assert.equal(canVisit('suppliers.show', staff), false);
    assert.equal(canVisit('categories.index', staff), false);
});

test('any-of and all-of specs', () => {
    assert.equal(canVisit('warehouses.edit', ['manage_warehouse_users']), true);
    assert.equal(canVisit('reports.dead-stock', ['view_reports', 'view_products']), false);
    assert.equal(canVisit('reports.dead-stock', ['view_reports', 'view_products', 'view_orders']), true);
});

test('a route without a permission gate is open', () => {
    assert.equal(canVisit('dashboard', []), true);
});
