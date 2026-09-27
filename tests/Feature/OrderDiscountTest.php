<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Line and order discounts, applied before tax, with every total recomputed
 * on the server.
 *
 * Tax base rule: discounts reduce the taxable merchandise amount and never
 * touch tax or shipping. A line discount comes off that line's gross
 * (qty x unit price); the order-level discount then comes off the merchandise
 * net of line discounts. Tax is supplied as an amount computed on those
 * discounted figures, and shipping is added last.
 */
class OrderDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Discount Org', 'email' => 'discount@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->organization->id, 'sku' => 'DISC-1', 'name' => 'Widget',
            'price' => 10.00, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@discount.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);
    }

    private function create(array $overrides = [], array $itemOverrides = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [
                array_merge(['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 10.00], $itemOverrides),
            ],
        ], $overrides), $this->admin);
    }

    private function assertReconciles(Order $order): void
    {
        $this->assertSame(
            (string) $order->total,
            \App\Support\Money::add(
                \App\Support\Money::subtract($order->subtotal, $order->discount_amount),
                $order->tax,
                $order->shipping,
            ),
            'subtotal - discount + tax + shipping must equal total',
        );
    }

    public function test_percent_line_discount(): void
    {
        $order = $this->create([], ['discount_type' => 'percent', 'discount_value' => 10]);
        $item = $order->items()->first();

        $this->assertSame('30.00', (string) $item->subtotal);
        $this->assertSame('percent', $item->discount_type->value);
        $this->assertSame('10.00', (string) $item->discount_value);
        $this->assertSame('3.00', (string) $item->discount_amount);
        $this->assertSame('27.00', (string) $item->total);

        $order->refresh();
        $this->assertSame('30.00', (string) $order->subtotal);
        $this->assertSame('3.00', (string) $order->discount_amount);
        $this->assertSame('27.00', (string) $order->total);
        $this->assertReconciles($order);
    }

    public function test_fixed_line_discount(): void
    {
        $order = $this->create([], ['discount_type' => 'fixed', 'discount_value' => 5]);

        $this->assertSame('5.00', (string) $order->items()->first()->discount_amount);
        $this->assertSame('25.00', (string) $order->fresh()->total);
    }

    public function test_percent_discount_rounds_half_up_to_the_cent(): void
    {
        $order = $this->create([], ['quantity' => 1, 'unit_price' => 10.05, 'discount_type' => 'percent', 'discount_value' => 15]);

        $this->assertSame('1.51', (string) $order->items()->first()->discount_amount);
        $this->assertSame('8.54', (string) $order->fresh()->total);
    }

    public function test_order_discount_applies_to_merchandise_net_of_line_discounts_and_before_tax_and_shipping(): void
    {
        $order = $this->create(
            ['discount_type' => 'percent', 'discount_value' => 10, 'tax' => 5, 'shipping' => 4],
            ['discount_type' => 'percent', 'discount_value' => 10],
        )->fresh('items');

        // Line: 30.00 gross - 3.00 = 27.00 net. Order: 10% of 27.00 = 2.70,
        // not 10% of 30.00, and not of tax or shipping.
        $this->assertSame('30.00', (string) $order->subtotal);
        $this->assertSame('5.70', (string) $order->discount_amount);
        $this->assertSame('2.70', $order->orderDiscountAmount());
        $this->assertSame('3.00', $order->lineDiscountTotal());
        $this->assertSame('5.00', (string) $order->tax);
        $this->assertSame('4.00', (string) $order->shipping);
        $this->assertSame('33.30', (string) $order->total);
        $this->assertReconciles($order);
    }

    public function test_fixed_order_discount(): void
    {
        $order = $this->create(['discount_type' => 'fixed', 'discount_value' => 7.5])->fresh();

        $this->assertSame('fixed', $order->discount_type->value);
        $this->assertSame('7.50', (string) $order->discount_value);
        $this->assertSame('7.50', (string) $order->discount_amount);
        $this->assertSame('22.50', (string) $order->total);
    }

    public function test_client_supplied_totals_are_ignored(): void
    {
        $order = $this->create(['subtotal' => 1, 'discount_amount' => 999, 'total' => 0.01])->fresh();

        $this->assertSame('30.00', (string) $order->subtotal);
        $this->assertSame('0.00', (string) $order->discount_amount);
        $this->assertSame('30.00', (string) $order->total);
    }

    public function test_a_fixed_line_discount_cannot_exceed_the_line(): void
    {
        try {
            $this->create([], ['discount_type' => 'fixed', 'discount_value' => 30.01]);
            $this->fail('Expected a validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.discount_value', $e->errors());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(100, (int) $this->product->fresh()->stock, 'a rejected order must not move stock');
    }

    public function test_a_percent_discount_cannot_exceed_100(): void
    {
        $this->expectException(ValidationException::class);

        $this->create([], ['discount_type' => 'percent', 'discount_value' => 100.01]);
    }

    public function test_an_order_discount_cannot_exceed_the_discounted_merchandise(): void
    {
        try {
            // 30.00 - 10.00 line discount leaves 20.00 to discount.
            $this->create(['discount_type' => 'fixed', 'discount_value' => 20.01], ['discount_type' => 'fixed', 'discount_value' => 10]);
            $this->fail('Expected a validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount_value', $e->errors());
        }
    }

    public function test_a_full_discount_gives_a_zero_total_not_a_negative_one(): void
    {
        $order = $this->create(['discount_type' => 'percent', 'discount_value' => 100])->fresh();

        $this->assertSame('0.00', (string) $order->total);
    }

    public function test_order_total_calculation_filter_is_applied_to_the_computed_total(): void
    {
        $seen = [];
        add_filter('order_total_calculation', function ($total, $order) use (&$seen) {
            $seen[] = [$total, $order instanceof Order];

            return \App\Support\Money::add($total, '1.25');
        });

        $order = $this->create(['discount_type' => 'fixed', 'discount_value' => 5])->fresh();

        $this->assertSame([['25.00', true]], $seen, 'the filter runs once, with the discounted total and the order');
        $this->assertSame('26.25', (string) $order->total);
    }

    public function test_a_filter_cannot_produce_a_negative_total(): void
    {
        add_filter('order_total_calculation', fn () => '-1.00');

        $this->expectException(ValidationException::class);

        $this->create();
    }

    public function test_web_store_persists_discounts_and_recomputes_totals(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), [
            'customer_name' => 'Web Customer',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'tax' => 2,
            'shipping' => 3,
            'discount_type' => 'fixed',
            'discount_value' => 4,
            'total' => 1, // ignored
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 10, 'discount_type' => 'percent', 'discount_value' => 50],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('orders.index'));

        $order = Order::firstOrFail();
        // 20.00 gross - 10.00 line - 4.00 order + 2.00 tax + 3.00 shipping
        $this->assertSame('14.00', (string) $order->discount_amount);
        $this->assertSame('11.00', (string) $order->total);
    }

    public function test_web_store_rejects_a_discount_over_the_line_as_a_field_error(): void
    {
        $this->actingAs($this->admin)->from(route('orders.create'))->post(route('orders.store'), [
            'customer_name' => 'Web Customer',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10, 'discount_type' => 'fixed', 'discount_value' => 11],
            ],
        ])->assertSessionHasErrors('items.0.discount_value');

        $this->assertSame(0, Order::count());
    }

    public function test_web_store_rejects_an_unknown_discount_type(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), [
            'customer_name' => 'Web Customer',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'discount_type' => 'bogus',
            'discount_value' => 1,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertSessionHasErrors('discount_type');
    }

    public function test_web_update_recomputes_discounts_on_the_server(): void
    {
        $order = $this->create();

        $this->actingAs($this->admin)->put(route('orders.update', $order), [
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'tax' => 1,
            'shipping' => 0,
            'discount_type' => 'percent',
            'discount_value' => 50,
            'total' => 999, // ignored
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 10, 'discount_type' => 'fixed', 'discount_value' => 10],
            ],
        ])->assertSessionHasNoErrors();

        $order->refresh();
        // 40 gross - 10 line = 30; 50% order = 15; + 1 tax
        $this->assertSame('40.00', (string) $order->subtotal);
        $this->assertSame('25.00', (string) $order->discount_amount);
        $this->assertSame('16.00', (string) $order->total);
        $this->assertSame('10.00', (string) $order->items()->first()->discount_amount);
        $this->assertReconciles($order);
    }

    public function test_web_update_can_remove_discounts(): void
    {
        $order = $this->create(['discount_type' => 'fixed', 'discount_value' => 5], ['discount_type' => 'fixed', 'discount_value' => 5]);

        $this->actingAs($this->admin)->put(route('orders.update', $order), [
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'discount_type' => null,
            'discount_value' => null,
            'items' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 10]],
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNull($order->discount_type);
        $this->assertSame('0.00', (string) $order->discount_amount);
        $this->assertSame('30.00', (string) $order->total);
    }

    public function test_show_exposes_discount_breakdown(): void
    {
        $order = $this->create(['discount_type' => 'fixed', 'discount_value' => 2], ['discount_type' => 'fixed', 'discount_value' => 3]);

        $this->actingAs($this->admin)->get(route('orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->where('order.discount_amount', '5.00')
                ->where('order.line_discount_total', '3.00')
                ->where('order.order_discount_amount', '2.00')
                // A plain list the page can iterate, not a {data: [...]} envelope.
                ->where('order.items.0.discount_amount', '3.00')
                ->where('order.items.0.discount_type', 'fixed')
            );
    }
}
