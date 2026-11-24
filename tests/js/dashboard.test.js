import { test } from 'node:test';
import assert from 'node:assert/strict';
import { categoryBreakdown, stockLevel } from '../../resources/js/lib/dashboard.js';

test('category shares use the valuation total without combining currencies', () => {
    const input = [
        { name: 'Other', value: 20, values: [{ currency: 'CAD', amount: 900 }] },
        { name: 'Tools', value: 80 },
    ];
    const rows = categoryBreakdown(input, 100);
    assert.deepEqual(
        rows.map(({ name, share }) => [name, share]),
        [
            ['Tools', 80],
            ['Other', 20],
        ],
    );
    assert.equal(input[0].name, 'Other');
    assert.equal(
        rows.reduce((sum, row) => sum + row.share, 0),
        100,
    );
});

test('zero or negative valuations do not produce misleading share bars', () => {
    for (const total of [0, -10, NaN]) assert.equal(categoryBreakdown([{ value: 10 }], total)[0].share, null);
    assert.deepEqual(
        categoryBreakdown([{ value: -5 }, { value: 15 }], 10).map((row) => row.share),
        [null, null],
    );
});

test('stock review uses effective variant stock and preserves the shortfall', () => {
    assert.deepEqual(stockLevel({ stock: 0, effective_stock: 3, min_stock: 10 }), { stock: 3, minimum: 10, shortfall: 7, percent: 30 });
    assert.deepEqual(stockLevel({ stock: -2, min_stock: 10 }), { stock: -2, minimum: 10, shortfall: 12, percent: 0 });
    assert.deepEqual(stockLevel({ stock: 20, min_stock: 10 }), { stock: 20, minimum: 10, shortfall: 0, percent: 100 });
    assert.deepEqual(stockLevel({ stock: 0, min_stock: 0 }), { stock: 0, minimum: 0, shortfall: 0, percent: 0 });
});

test('period change compares with last month and shows nothing without history', async () => {
    const { periodChange } = await import('../../resources/js/lib/dashboard.js');
    assert.equal(periodChange(1500, 1000), 50);
    assert.equal(periodChange(500, 1000), -50);
    assert.equal(periodChange(0, 1000), -100);
    for (const previous of [0, -5, null, undefined, 'x']) assert.equal(periodChange(100, previous), null);
});

test('stock urgency is critical at zero or under a quarter of minimum', async () => {
    const { stockUrgency } = await import('../../resources/js/lib/dashboard.js');
    assert.equal(stockUrgency(stockLevel({ stock: 0, min_stock: 10 })), 'critical');
    assert.equal(stockUrgency(stockLevel({ stock: -3, min_stock: 10 })), 'critical');
    assert.equal(stockUrgency(stockLevel({ stock: 2, min_stock: 10 })), 'critical');
    assert.equal(stockUrgency(stockLevel({ stock: 3, min_stock: 10 })), 'low');
    assert.equal(stockUrgency(stockLevel({ stock: 3, min_stock: 0 })), 'low');
});

test('movement summary totals the period and ignores bad values', async () => {
    const { movementSummary } = await import('../../resources/js/lib/dashboard.js');
    const summary = movementSummary([
        { date: '2026-11-01', in: 12, out: 4 },
        { date: '2026-11-02', in: '3', out: null },
        { date: '2026-11-03', in: -2, out: 20 },
    ]);
    assert.equal(summary.totalIn, 15);
    assert.equal(summary.totalOut, 24);
    assert.equal(summary.peak, 20);
    assert.equal(summary.days[2].in, 0);
    assert.deepEqual(movementSummary([]), { days: [], totalIn: 0, totalOut: 0, peak: 0 });
});

test('product initials come from the first two words', async () => {
    const { productInitials } = await import('../../resources/js/lib/dashboard.js');
    assert.equal(productInitials('Laptop Stand Aluminum'), 'LS');
    assert.equal(productInitials('stapler'), 'ST');
    assert.equal(productInitials('  '), '?');
});
