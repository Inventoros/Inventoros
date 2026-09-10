<?php

namespace Tests\Feature;

use App\Exports\OrdersExport;
use App\Models\Auth\Organization;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The orders export carried a "Discount" column and read $order->discount to
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

    public function test_the_phantom_discount_column_is_gone(): void
    {
        $export = new OrdersExport($this->organization->id, []);

        $this->assertNotContains('Discount', $export->headings());
        $this->assertContains('Shipping', $export->headings());
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
