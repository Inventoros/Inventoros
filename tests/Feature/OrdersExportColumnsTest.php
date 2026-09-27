<?php

namespace Tests\Feature;

use App\Exports\OrdersExport;
use App\Models\Auth\Organization;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * History: the orders export carried a "Discount" column and read $order->discount to
 * fill it. There is no discount column on orders, in any migration, and no
 * such accessor on the model -- Eloquent returns null for an unknown
 * attribute, so the cell was silently blank in every export ever produced.
 *
 * It also had no "Shipping" column, and shipping IS a real money column on
 * orders. So an exported row of an order with shipping did not add up:
 * subtotal + tax != total, with nothing in the sheet accounting for the gap.
 * Anyone reconciling would find a discrepancy and no explanation for it.
 */
class OrdersExportColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Export Org',
            'email' => 'export@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
    }

    public function test_every_heading_maps_to_a_real_value(): void
    {
        $order = Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-EXPORT-1',
            'status' => 'pending',
            'subtotal' => 100.00,
            'tax' => 13.00,
            'shipping' => 9.50,
            'total' => 122.50,
            'currency' => 'USD',
            'order_date' => now(),
            // Every nullable field is populated on purpose, so a null cell in
            // the assertion below can only mean a heading with nothing behind
            // it -- which is exactly what "Discount" was.
            'customer_name' => 'Acme Ltd',
            'customer_email' => 'ap@acme.test',
            'notes' => 'Leave at reception.',
        ]);

        $export = new OrdersExport($this->organization->id, []);
        $row = array_combine($export->headings(), $export->map($order->fresh('items')));

        $this->assertNotContains(null, $row, 'A heading maps to a null cell: '.json_encode(array_keys($row, null, true)));
    }

    public function test_the_money_columns_reconcile(): void
    {
        $order = Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-EXPORT-2',
            'status' => 'pending',
            'subtotal' => 100.00,
            'tax' => 13.00,
            'shipping' => 9.50,
            'total' => 122.50,
            'currency' => 'USD',
            'order_date' => now(),
        ]);

        $export = new OrdersExport($this->organization->id, []);
        $row = array_combine($export->headings(), $export->map($order->fresh('items')));

        $this->assertSame('100.00', $row['Subtotal']);
        $this->assertSame('13.00', $row['Tax']);
        $this->assertSame('9.50', $row['Shipping']);
        $this->assertSame('122.50', $row['Total']);

        $this->assertEqualsWithDelta(
            (float) $row['Total'],
            (float) $row['Subtotal'] + (float) $row['Tax'] + (float) $row['Shipping'],
            0.001,
            'The exported row must account for the whole order total.',
        );
    }

    public function test_the_discount_column_is_real_and_the_row_reconciles(): void
    {
        // Orders now carry real discounts, so the Discount column is back and
        // backed by a stored value. It holds the whole discount (lines plus
        // order level) so the row still adds up on its own.
        $order = Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-EXPORT-4',
            'status' => 'pending',
            'subtotal' => 100.00,
            'discount_type' => 'percent',
            'discount_value' => 10,
            'discount_amount' => 12.50,
            'tax' => 8.75,
            'shipping' => 5.00,
            'total' => 101.25,
            'currency' => 'USD',
            'order_date' => now(),
        ]);

        $export = new OrdersExport($this->organization->id, []);
        $row = array_combine($export->headings(), $export->map($order->fresh('items')));

        $this->assertContains('Shipping', $export->headings());
        $this->assertSame('12.50', $row['Discount']);
        $this->assertSame(
            $row['Total'],
            \App\Support\Money::add(\App\Support\Money::subtract($row['Subtotal'], $row['Discount']), $row['Tax'], $row['Shipping']),
            'Subtotal - Discount + Tax + Shipping must equal Total.',
        );
    }

    public function test_an_order_created_with_discounts_exports_a_reconciling_row(): void
    {
        $admin = \App\Models\User::create([
            'name' => 'Exporter', 'email' => 'exporter@organization.com', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);
        $product = \App\Models\Inventory\Product::create([
            'organization_id' => $this->organization->id, 'sku' => 'EXP-1', 'name' => 'Exported',
            'price' => 12.34, 'currency' => 'USD', 'stock' => 50, 'min_stock' => 0, 'is_active' => true,
        ]);

        $order = app(\App\Services\OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now(),
            'tax' => 1.11, 'shipping' => 2.22,
            'discount_type' => 'percent', 'discount_value' => 7,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 12.34, 'discount_type' => 'percent', 'discount_value' => 15],
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 9.99, 'discount_type' => 'fixed', 'discount_value' => 0.99],
            ],
        ], $admin);

        $export = new OrdersExport($this->organization->id, []);
        $row = array_combine($export->headings(), $export->map($order->fresh('items')));

        $this->assertSame(
            $row['Total'],
            \App\Support\Money::add(\App\Support\Money::subtract($row['Subtotal'], $row['Discount']), $row['Tax'], $row['Shipping']),
        );
    }

    public function test_headings_and_mapped_cells_stay_the_same_length(): void
    {
        $order = Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-EXPORT-3',
            'status' => 'pending',
            'total' => 0,
            'currency' => 'USD',
            'order_date' => now(),
        ]);

        $export = new OrdersExport($this->organization->id, []);

        $this->assertCount(
            count($export->headings()),
            $export->map($order->fresh('items')),
            'A row with a different width than the header shifts every column after the gap.',
        );
    }
}
