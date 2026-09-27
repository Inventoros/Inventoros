<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The web order form sells variant products (a variant picker per line) and
 * links an order to a saved customer (customer_id), with both references
 * validated against the acting user's organization.
 */
final class OrderFormVariantAndCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private User $admin;

    private Product $shirt;

    private ProductVariant $small;

    private ProductVariant $large;

    private Product $plain;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Shop', 'email' => 'shop@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->otherOrg = Organization::create([
            'name' => 'Other', 'email' => 'other@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@shop.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->shirt = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SHIRT', 'name' => 'Shirt',
            'price' => 20, 'currency' => 'USD', 'stock' => 50, 'min_stock' => 0,
            'is_active' => true, 'has_variants' => true,
        ]);
        $this->small = $this->variant($this->shirt, 'SHIRT-S', 'Small', 10, 18.5);
        $this->large = $this->variant($this->shirt, 'SHIRT-L', 'Large', 4, 22);

        $this->plain = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'MUG', 'name' => 'Mug',
            'price' => 8, 'currency' => 'USD', 'stock' => 30, 'min_stock' => 0, 'is_active' => true,
        ]);
    }

    private function variant(Product $product, string $sku, string $title, int $stock, float $price): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id, 'organization_id' => $product->organization_id,
            'sku' => $sku, 'title' => $title, 'option_values' => ['Size' => $title],
            'price' => $price, 'stock' => $stock, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function payload(array $items, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Walk-in',
            'status' => 'pending',
            'order_date' => now()->format('Y-m-d'),
            'items' => $items,
        ], $overrides);
    }

    // ==================== VARIANT LINES ====================

    public function test_create_form_exposes_each_products_active_variants(): void
    {
        $this->variant($this->shirt, 'SHIRT-X', 'Retired', 3, 20)->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('orders.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Create')
                ->where('products', function ($products) {
                    $shirt = collect($products)->firstWhere('sku', 'SHIRT');
                    $mug = collect($products)->firstWhere('sku', 'MUG');

                    return $shirt['has_variants'] === true
                        && collect($shirt['variants'])->pluck('sku')->sort()->values()->all() === ['SHIRT-L', 'SHIRT-S']
                        && collect($shirt['variants'])->firstWhere('sku', 'SHIRT-S')['stock'] === 10
                        && $mug['variants'] === [];
                })
            );
    }

    public function test_storing_a_variant_line_decrements_the_variant_not_the_parent(): void
    {
        $this->actingAs($this->admin)
            ->post(route('orders.store'), $this->payload([[
                'product_id' => $this->shirt->id,
                'product_variant_id' => $this->small->id,
                'quantity' => 3,
                'unit_price' => 18.5,
            ]]))
            ->assertRedirect(route('orders.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(7, (int) $this->small->fresh()->stock);
        $this->assertSame(50, (int) $this->shirt->fresh()->stock);
        $this->assertDatabaseHas('order_items', [
            'product_id' => $this->shirt->id,
            'product_variant_id' => $this->small->id,
            'sku' => 'SHIRT-S',
            'quantity' => 3,
        ]);
    }

    public function test_store_rejects_a_variant_that_belongs_to_another_product(): void
    {
        $hat = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'HAT', 'name' => 'Hat',
            'price' => 10, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0,
            'is_active' => true, 'has_variants' => true,
        ]);
        $hatVariant = $this->variant($hat, 'HAT-S', 'Small', 5, 10);

        $this->actingAs($this->admin)
            ->post(route('orders.store'), $this->payload([[
                'product_id' => $this->shirt->id,
                'product_variant_id' => $hatVariant->id,
                'quantity' => 1,
                'unit_price' => 10,
            ]]))
            ->assertSessionHasErrors('items.0.product_variant_id');

        $this->assertSame(0, Order::count());
        $this->assertSame(5, (int) $hatVariant->fresh()->stock);
    }

    public function test_store_rejects_a_variant_from_another_organization(): void
    {
        $foreignProduct = Product::create([
            'organization_id' => $this->otherOrg->id, 'sku' => 'F', 'name' => 'Foreign',
            'price' => 1, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0,
            'is_active' => true, 'has_variants' => true,
        ]);
        $foreignVariant = $this->variant($foreignProduct, 'F-1', 'One', 5, 1);

        $this->actingAs($this->admin)
            ->post(route('orders.store'), $this->payload([[
                'product_id' => $this->shirt->id,
                'product_variant_id' => $foreignVariant->id,
                'quantity' => 1,
                'unit_price' => 1,
            ]]))
            ->assertSessionHasErrors('items.0.product_variant_id');

        $this->assertSame(0, Order::count());
    }

    public function test_store_requires_a_variant_for_a_variant_product(): void
    {
        $this->actingAs($this->admin)
            ->post(route('orders.store'), $this->payload([[
                'product_id' => $this->shirt->id,
                'quantity' => 1,
                'unit_price' => 20,
            ]]))
            ->assertSessionHasErrors('items.0.product_variant_id');

        $this->assertSame(0, Order::count());
    }

    public function test_edit_can_add_a_variant_line_and_switch_a_lines_variant(): void
    {
        // Existing order: 2 x Small (the variant already carries the sale).
        $this->small->update(['stock' => 8]);
        $order = Order::create([
            'organization_id' => $this->org->id, 'order_number' => 'ORD-1', 'source' => 'manual',
            'customer_name' => 'C', 'status' => 'pending', 'subtotal' => 37, 'tax' => 0,
            'shipping' => 0, 'total' => 37, 'currency' => 'USD', 'order_date' => now(),
        ]);
        $line = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->shirt->id,
            'product_variant_id' => $this->small->id, 'product_name' => 'Shirt', 'sku' => 'SHIRT-S',
            'quantity' => 2, 'unit_price' => 18.5, 'subtotal' => 37, 'tax' => 0, 'total' => 37,
        ]);

        // Switch the Small line to Large, and add a Mug line.
        $this->actingAs($this->admin)
            ->put(route('orders.update', $order), $this->payload([
                ['id' => $line->id, 'product_id' => $this->shirt->id, 'product_variant_id' => $this->large->id, 'quantity' => 1, 'unit_price' => 22],
                ['product_id' => $this->plain->id, 'quantity' => 2, 'unit_price' => 8],
            ]))
            ->assertRedirect(route('orders.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(10, (int) $this->small->fresh()->stock); // 2 released
        $this->assertSame(3, (int) $this->large->fresh()->stock);  // 1 sold
        $this->assertSame(28, (int) $this->plain->fresh()->stock);
        $this->assertSame(50, (int) $this->shirt->fresh()->stock);
        $this->assertEqualsCanonicalizing(
            [$this->large->id, null],
            $order->fresh()->items->pluck('product_variant_id')->all()
        );
    }

    public function test_update_rejects_a_variant_that_belongs_to_another_product(): void
    {
        $order = Order::create([
            'organization_id' => $this->org->id, 'order_number' => 'ORD-2', 'source' => 'manual',
            'customer_name' => 'C', 'status' => 'pending', 'subtotal' => 0, 'tax' => 0,
            'shipping' => 0, 'total' => 0, 'currency' => 'USD', 'order_date' => now(),
        ]);

        $this->actingAs($this->admin)
            ->put(route('orders.update', $order), $this->payload([
                ['product_id' => $this->plain->id, 'product_variant_id' => $this->small->id, 'quantity' => 1, 'unit_price' => 8],
            ]))
            ->assertSessionHasErrors('items.0.product_variant_id');
    }

    // ==================== CUSTOMER LINK ====================

    public function test_store_persists_the_chosen_customer(): void
    {
        $customer = Customer::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme']);

        $this->actingAs($this->admin)
            ->post(route('orders.store'), $this->payload(
                [['product_id' => $this->plain->id, 'quantity' => 1, 'unit_price' => 8]],
                ['customer_id' => $customer->id, 'customer_name' => 'Acme'],
            ))
            ->assertRedirect(route('orders.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame($customer->id, Order::sole()->customer_id);
    }

    public function test_store_accepts_a_one_off_customer_without_customer_id(): void
    {
        $this->actingAs($this->admin)
            ->post(route('orders.store'), $this->payload(
                [['product_id' => $this->plain->id, 'quantity' => 1, 'unit_price' => 8]],
            ))
            ->assertSessionHasNoErrors();

        $this->assertNull(Order::sole()->customer_id);
    }

    public function test_store_rejects_a_customer_from_another_organization(): void
    {
        $foreign = Customer::factory()->create(['organization_id' => $this->otherOrg->id]);

        $this->actingAs($this->admin)
            ->post(route('orders.store'), $this->payload(
                [['product_id' => $this->plain->id, 'quantity' => 1, 'unit_price' => 8]],
                ['customer_id' => $foreign->id],
            ))
            ->assertSessionHasErrors('customer_id');

        $this->assertSame(0, Order::count());
    }

    public function test_update_can_link_and_rejects_a_foreign_customer(): void
    {
        $customer = Customer::factory()->create(['organization_id' => $this->org->id]);
        $foreign = Customer::factory()->create(['organization_id' => $this->otherOrg->id]);
        $order = Order::create([
            'organization_id' => $this->org->id, 'order_number' => 'ORD-3', 'source' => 'manual',
            'customer_name' => 'C', 'status' => 'pending', 'subtotal' => 0, 'tax' => 0,
            'shipping' => 0, 'total' => 0, 'currency' => 'USD', 'order_date' => now(),
        ]);
        $items = [['product_id' => $this->plain->id, 'quantity' => 1, 'unit_price' => 8]];

        $this->actingAs($this->admin)
            ->put(route('orders.update', $order), $this->payload($items, ['customer_id' => $foreign->id]))
            ->assertSessionHasErrors('customer_id');
        $this->assertNull($order->fresh()->customer_id);

        $this->actingAs($this->admin)
            ->put(route('orders.update', $order), $this->payload($items, ['customer_id' => $customer->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($customer->id, $order->fresh()->customer_id);
    }

    public function test_edit_form_includes_the_linked_customer(): void
    {
        $customer = Customer::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme']);
        $order = Order::create([
            'organization_id' => $this->org->id, 'customer_id' => $customer->id, 'order_number' => 'ORD-4',
            'source' => 'manual', 'customer_name' => 'Acme', 'status' => 'pending', 'subtotal' => 0,
            'tax' => 0, 'shipping' => 0, 'total' => 0, 'currency' => 'USD', 'order_date' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('orders.edit', $order))
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Edit')
                ->where('order.customer_id', $customer->id)
                ->where('order.customer.name', 'Acme')
            );
    }

    public function test_customer_lookup_is_scoped_to_the_organization(): void
    {
        Customer::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme Retail', 'email' => 'buy@acme.test']);
        Customer::factory()->create(['organization_id' => $this->org->id, 'name' => 'Bolt Ltd', 'is_active' => false]);
        Customer::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Acme Foreign']);

        $response = $this->actingAs($this->admin)
            ->getJson(route('orders.customer-lookup', ['q' => 'acme']))
            ->assertOk();

        $this->assertSame(['Acme Retail'], collect($response->json('customers'))->pluck('name')->all());
        $this->assertArrayHasKey('shipping_address', $response->json('customers.0'));
    }

    public function test_customer_show_lists_the_customers_orders_newest_first(): void
    {
        $customer = Customer::factory()->create(['organization_id' => $this->org->id]);
        foreach (['ORD-OLD' => now()->subDays(5), 'ORD-NEW' => now()] as $number => $date) {
            Order::create([
                'organization_id' => $this->org->id, 'customer_id' => $customer->id, 'order_number' => $number,
                'source' => 'manual', 'customer_name' => 'C', 'status' => 'pending', 'subtotal' => 0,
                'tax' => 0, 'shipping' => 0, 'total' => 0, 'currency' => 'USD', 'order_date' => $date,
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer))
            ->assertInertia(fn ($page) => $page
                ->component('Customers/Show')
                ->where('customer.orders.0.order_number', 'ORD-NEW')
                ->where('customer.orders.1.order_number', 'ORD-OLD')
            );
    }
}
