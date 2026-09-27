<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exports\ExportFactory;
use App\Exports\OrderLinesExport;
use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The line-items order export: one row per order line, with the order's
 * header fields and totals repeated on every line.
 */
final class OrderLinesExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
    }

    private function order(Organization $org, string $number, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'organization_id' => $org->id,
            'order_number' => $number,
            'status' => 'processing',
            'subtotal' => 30,
            'tax' => 3,
            'shipping' => 5,
            'total' => 38,
            'currency' => 'USD',
            'order_date' => '2026-03-04 10:00:00',
            'customer_name' => 'Walk-in',
        ], $attributes));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(OrderLinesExport $export): array
    {
        return $export->query()->get()
            ->map(fn ($item) => array_combine($export->headings(), $export->map($item)))
            ->all();
    }

    public function test_it_exports_one_row_per_line_with_order_totals_repeated(): void
    {
        $customer = Customer::create(['organization_id' => $this->org->id, 'name' => 'Acme Ltd', 'email' => 'ap@acme.test']);
        $plain = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'PLAIN-1', 'name' => 'Plain widget',
            'price' => 10, 'currency' => 'USD', 'stock' => 10, 'min_stock' => 0,
        ]);
        $shirt = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SHIRT', 'name' => 'Shirt',
            'price' => 20, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'has_variants' => true,
        ]);
        $large = ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $shirt->id, 'sku' => 'SHIRT-L',
            'title' => 'Large', 'option_values' => ['Size' => 'L'], 'price' => 20, 'stock' => 5,
        ]);

        $order = $this->order($this->org, 'ORD-0001', ['customer_id' => $customer->id, 'external_reference' => 'SHOP-77']);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $plain->id, 'product_name' => 'Plain widget', 'sku' => 'PLAIN-1',
            'quantity' => 1, 'unit_price' => 10, 'subtotal' => 10, 'tax' => 0, 'total' => 10,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $shirt->id, 'product_variant_id' => $large->id,
            'product_name' => 'Shirt', 'sku' => 'SHIRT-L',
            'quantity' => 1, 'unit_price' => 20, 'subtotal' => 20, 'tax' => 0, 'total' => 20,
        ]);

        $rows = $this->rows(new OrderLinesExport($this->org->id));

        $this->assertCount(2, $rows);
        [$first, $second] = $rows;

        $this->assertSame('ORD-0001', $first['Order Number']);
        $this->assertSame('SHOP-77', $first['External Reference']);
        $this->assertSame('2026-03-04 10:00:00', $first['Order Date']);
        $this->assertSame('processing', $first['Status']);
        $this->assertSame('Acme Ltd', $first['Customer Name']);
        $this->assertSame('ap@acme.test', $first['Customer Email']);
        $this->assertSame(1, $first['Line']);
        $this->assertSame('PLAIN-1', $first['Product SKU']);
        $this->assertSame('', $first['Variant SKU']);
        $this->assertEquals(10, $first['Line Total']);

        $this->assertSame(2, $second['Line']);
        $this->assertSame('SHIRT', $second['Product SKU']);
        $this->assertSame('Shirt', $second['Product Name']);
        $this->assertSame('SHIRT-L', $second['Variant SKU']);
        $this->assertSame('Large', $second['Variant Title']);
        $this->assertEquals(1, $second['Quantity']);
        $this->assertEquals(20, $second['Unit Price']);

        foreach ($rows as $row) {
            $this->assertEquals(30, $row['Order Subtotal']);
            $this->assertEquals(3, $row['Order Tax']);
            $this->assertEquals(5, $row['Order Shipping']);
            $this->assertEquals(38, $row['Order Total']);
        }
    }

    public function test_it_neutralises_formula_cells(): void
    {
        $order = $this->order($this->org, 'ORD-0002', ['customer_name' => '=HYPERLINK("https://evil")']);
        OrderItem::create([
            'order_id' => $order->id, 'product_name' => '+cmd', 'sku' => '-SKU',
            'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1, 'tax' => 0, 'total' => 1,
        ]);

        $row = $this->rows(new OrderLinesExport($this->org->id))[0];

        $this->assertSame('\'=HYPERLINK("https://evil")', $row['Customer Name']);
        $this->assertSame("'+cmd", $row['Product Name']);
        $this->assertSame("'-SKU", $row['Product SKU']);
    }

    public function test_it_only_exports_the_organizations_orders_and_applies_filters(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'x@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        foreach ([[$this->org, 'ORD-A', 'pending'], [$this->org, 'ORD-B', 'shipped'], [$other, 'ORD-X', 'pending']] as [$org, $number, $status]) {
            $order = $this->order($org, $number, ['status' => $status]);
            OrderItem::create([
                'order_id' => $order->id, 'product_name' => 'Thing', 'sku' => 'T',
                'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1, 'tax' => 0, 'total' => 1,
            ]);
        }

        $all = array_column($this->rows(new OrderLinesExport($this->org->id)), 'Order Number');
        sort($all);
        $this->assertSame(['ORD-A', 'ORD-B'], $all);

        $shipped = array_column($this->rows(new OrderLinesExport($this->org->id, ['status' => 'shipped'])), 'Order Number');
        $this->assertSame(['ORD-B'], $shipped);
    }

    public function test_the_factory_and_route_expose_the_lines_mode(): void
    {
        $this->assertInstanceOf(OrderLinesExport::class, ExportFactory::make('order_lines', $this->org->id));

        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get(route('import-export.export-orders', ['mode' => 'lines']));

        $response->assertOk();
        $this->assertStringStartsWith('attachment; filename=order_lines_', (string) $response->headers->get('content-disposition'));
    }
}
