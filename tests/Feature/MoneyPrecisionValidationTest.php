<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\OrdersImport;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreateOrderTool;
use App\Mcp\Tools\RecordPaymentTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\OrderPayment;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Money is stored to the cent. A unit price, discount, tax, shipping or
 * payment amount with more decimals is rejected on every surface instead of
 * being silently rounded (or, before, truncated) into a different figure
 * than the one entered. Percentage discounts follow the same rule: the
 * discount_value column stores two decimals, so 12.345% could not be kept.
 */
class MoneyPrecisionValidationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Cents', 'email' => 'cents@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@cents.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'CENT-1', 'name' => 'Item',
            'price' => 10, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
    }

    private function webOrder(array $overrides = [], array $item = []): array
    {
        return $overrides + [
            'customer_name' => 'Web', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [$item + ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '10.00']],
        ];
    }

    public function test_web_create_rejects_sub_cent_money(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('orders.store'), $this->webOrder([], ['unit_price' => '10.005']))->assertSessionHasErrors('items.0.unit_price');
        $this->post(route('orders.store'), $this->webOrder([], ['discount_type' => 'fixed', 'discount_value' => '1.001']))->assertSessionHasErrors('items.0.discount_value');
        $this->post(route('orders.store'), $this->webOrder([], ['discount_type' => 'percent', 'discount_value' => '12.345']))->assertSessionHasErrors('items.0.discount_value');
        $this->post(route('orders.store'), $this->webOrder(['discount_type' => 'fixed', 'discount_value' => '0.555']))->assertSessionHasErrors('discount_value');
        $this->post(route('orders.store'), $this->webOrder(['tax' => '1.234', 'shipping' => '2.345']))->assertSessionHasErrors(['tax', 'shipping']);

        $this->assertSame(0, Order::count());

        $this->post(route('orders.store'), $this->webOrder(['tax' => '1.5'], ['unit_price' => '9.99', 'discount_type' => 'percent', 'discount_value' => '12.5']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
    }

    public function test_web_update_rejects_sub_cent_money(): void
    {
        $order = app(OrderService::class)->create($this->webOrder(), $this->admin);

        $this->actingAs($this->admin)
            ->put(route('orders.update', $order), $this->webOrder(['discount_type' => 'fixed', 'discount_value' => '0.001'], ['unit_price' => '10.001']))
            ->assertSessionHasErrors(['items.0.unit_price', 'discount_value']);
    }

    public function test_rest_rejects_sub_cent_money(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Api',
            'discount_type' => 'fixed', 'discount_value' => 0.001,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10.005, 'tax' => 0.125, 'discount_type' => 'fixed', 'discount_value' => 0.015]],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'discount_value', 'items.0.unit_price', 'items.0.tax', 'items.0.discount_value',
        ]);
        $this->assertSame(0, Order::count());

        $order = app(OrderService::class)->create($this->webOrder(), $this->admin);
        $this->putJson("/api/v1/orders/{$order->id}", ['discount_type' => 'percent', 'discount_value' => 5.555])
            ->assertStatus(422)->assertJsonValidationErrors('discount_value');
    }

    public function test_graphql_rejects_sub_cent_money(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $query = sprintf(
            'mutation { createOrder(customer_name: "Gql", items: [{product_id: %d, quantity: 1, unit_price: 10.005}]) { id } }',
            $this->product->id,
        );
        $this->postJson('/graphql', ['query' => $query])->assertJsonPath('data.createOrder', null);
        $this->assertNotEmpty($this->postJson('/graphql', ['query' => $query])->json('errors'));

        $query = sprintf(
            'mutation { createOrder(customer_name: "Gql", discount_type: "fixed", discount_value: 0.555, items: [{product_id: %d, quantity: 1, unit_price: 10}]) { id } }',
            $this->product->id,
        );
        $this->assertNotEmpty($this->postJson('/graphql', ['query' => $query])->json('errors'));

        $this->assertSame(0, Order::count());

        $order = app(OrderService::class)->create($this->webOrder(), $this->admin);
        $this->assertNotEmpty($this->postJson('/graphql', ['query' => sprintf(
            'mutation { updateOrder(id: %d, discount_type: "fixed", discount_value: 0.555) { id } }', $order->id,
        )])->json('errors'));
    }

    public function test_mcp_rejects_sub_cent_money(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(CreateOrderTool::class, [
                'customer_name' => 'Mcp',
                'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10.005]],
            ])
            ->assertHasErrors();
        $this->assertSame(0, Order::count());

        $order = app(OrderService::class)->create($this->webOrder(), $this->admin);
        InventorosServer::actingAs($this->admin)
            ->tool(RecordPaymentTool::class, ['order_id' => $order->id, 'amount' => 1.005, 'method' => 'card'])
            ->assertHasErrors();
        $this->assertSame(0, OrderPayment::count());
    }

    public function test_payments_reject_sub_cent_amounts(): void
    {
        $order = app(OrderService::class)->create($this->webOrder(), $this->admin);

        $this->actingAs($this->admin)->post(route('orders.payments.store', $order), ['amount' => '1.005', 'method' => 'cash'])
            ->assertSessionHasErrors('amount');

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['amount' => 1.005, 'method' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame(0, OrderPayment::count());
    }

    public function test_import_rejects_sub_cent_money(): void
    {
        $csv = "external_reference,order_date,product_sku,quantity,unit_price,line_tax,order_tax,order_shipping\n"
            ."IMP-1,2026-01-05,CENT-1,1,10.005,,,\n"
            ."IMP-2,2026-01-05,CENT-1,1,10,0.125,,\n"
            ."IMP-3,2026-01-05,CENT-1,1,10,,1.001,2.002\n"
            ."IMP-4,2026-01-05,CENT-1,1,10.5,0.5,1,2\n";

        $import = new OrdersImport($this->admin);
        Excel::import($import, UploadedFile::fake()->createWithContent('orders.csv', $csv));

        $stats = $import->getStats();
        $this->assertSame(1, $stats['imported']);
        $this->assertSame(3, $stats['failed']);
        $this->assertSame(['IMP-4'], Order::pluck('external_reference')->all());
    }
}
