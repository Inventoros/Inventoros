<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\OrdersImport;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreateOrderTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * An order's currency defaults to the organization's currency on every
 * surface, and a line without a unit price is priced in the ORDER's currency:
 * the product's price for that currency (price_in_currencies), or its own
 * price when the order is in the product's currency. With neither, the line
 * is rejected; it never silently takes a price in another currency.
 */
class OrderCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Maple', 'email' => 'maple@org.com', 'currency' => 'CAD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@maple.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'MAPLE-1', 'name' => 'Syrup',
            'price' => 12.00, 'selling_price' => 15.00, 'currency' => 'CAD',
            'price_in_currencies' => ['USD' => '11.00', 'eur' => '10.50'],
            'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
    }

    private function create(array $data): Order
    {
        return app(OrderService::class)->create($data + [
            'customer_name' => 'Buyer', 'status' => 'pending', 'order_date' => now(),
        ], $this->admin);
    }

    // ------------------------------------------------------ web currency choice

    public function test_the_web_form_offers_the_currencies_and_defaults_to_the_organizations(): void
    {
        $this->withoutVite()->actingAs($this->admin)
            ->get(route('orders.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('defaultCurrency', 'CAD')
                ->where('currencies', fn ($currencies) => collect($currencies)->pluck('code')->contains('EUR')
                    && collect($currencies)->pluck('code')->contains('CAD'))
                // Products carry their prices per currency so the form can
                // prefill a line in the order's currency.
                ->where('products.0.currency', 'CAD')
                ->where('products.0.prices.USD', '11.00')
                ->where('products.0.prices.EUR', '10.50'));
    }

    public function test_a_web_order_can_be_placed_in_another_currency(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), [
            'customer_name' => 'Web', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'currency' => 'EUR',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '10.50']],
        ])->assertRedirect(route('orders.index'));

        $this->assertSame('EUR', Order::where('customer_name', 'Web')->value('currency'));
    }

    public function test_a_web_order_rejects_an_unknown_currency(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), [
            'customer_name' => 'Web', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'currency' => 'ZZZ',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '10.50']],
        ])->assertSessionHasErrors('currency');
    }

    public function test_the_customer_lookup_returns_the_customers_currency(): void
    {
        \App\Models\Customer::create([
            'organization_id' => $this->org->id, 'name' => 'Euro Buyer', 'code' => 'EURO', 'currency' => 'EUR', 'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('orders.customer-lookup', ['q' => 'Euro']))
            ->assertOk()
            ->assertJsonPath('customers.0.currency', 'EUR');
    }

    // ------------------------------------------------------------ defaults

    public function test_the_service_defaults_the_currency_to_the_organizations(): void
    {
        $order = $this->create(['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 5]]]);

        $this->assertSame('CAD', $order->fresh()->currency);
    }

    public function test_web_orders_take_the_organizations_currency(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), [
            'customer_name' => 'Web', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '15.00']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('CAD', Order::sole()->currency);
    }

    public function test_rest_defaults_to_the_organizations_currency_not_usd(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Api',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.currency', 'CAD')
            ->assertJsonPath('data.items.0.unit_price', '15.00')
            ->assertJsonPath('data.subtotal', '30.00');
    }

    public function test_graphql_defaults_to_the_organizations_currency(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/graphql', ['query' => sprintf(
            'mutation { createOrder(customer_name: "Gql", items: [{product_id: %d, quantity: 1}]) { currency subtotal } }',
            $this->product->id,
        )])->assertJsonMissingPath('errors')
            ->assertJsonPath('data.createOrder.currency', 'CAD')
            ->assertJsonPath('data.createOrder.subtotal', 15);
    }

    public function test_mcp_defaults_to_the_organizations_currency(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(CreateOrderTool::class, [
                'customer_name' => 'Mcp',
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            ])
            ->assertOk();

        $this->assertSame('CAD', Order::sole()->currency);
    }

    // ------------------------------------------------------------ pricing

    public function test_a_line_without_a_price_uses_the_products_price_for_the_order_currency(): void
    {
        $usd = $this->create(['currency' => 'USD', 'items' => [['product_id' => $this->product->id, 'quantity' => 2]]]);
        $this->assertSame('11.00', (string) $usd->items->sole()->unit_price);
        $this->assertSame('22.00', (string) $usd->subtotal);

        // Stored codes are matched case-insensitively ('eur' above).
        $eur = $this->create(['currency' => 'eur', 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]]);
        $this->assertSame('EUR', $eur->fresh()->currency);
        $this->assertSame('10.50', (string) $eur->items->sole()->unit_price);
    }

    public function test_a_line_in_the_products_own_currency_uses_its_selling_price(): void
    {
        $order = $this->create(['currency' => 'CAD', 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]]);

        $this->assertSame('15.00', (string) $order->items->sole()->unit_price);
    }

    public function test_a_line_with_no_price_in_the_order_currency_is_rejected(): void
    {
        try {
            $this->create(['currency' => 'GBP', 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]]);
            $this->fail('An order in a currency the product has no price for must be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['items.0.unit_price' => ['Syrup has no price in GBP. Enter a unit price for this line.']],
                $e->errors(),
            );
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(100, $this->product->fresh()->stock);
    }

    public function test_an_explicit_unit_price_is_always_used(): void
    {
        $order = $this->create(['currency' => 'GBP', 'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 9.25]]]);

        $this->assertSame('9.25', (string) $order->items->sole()->unit_price);
    }

    public function test_rest_reports_a_missing_currency_price_as_a_422(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Api', 'currency' => 'GBP',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.unit_price');
    }

    public function test_a_variant_with_its_own_price_is_not_priced_from_the_parents_foreign_price(): void
    {
        $shirt = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SHIRT', 'name' => 'Shirt', 'price' => 20,
            'currency' => 'CAD', 'price_in_currencies' => ['USD' => '15.00'],
            'stock' => 0, 'min_stock' => 0, 'has_variants' => true,
        ]);
        $large = ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $shirt->id, 'sku' => 'SHIRT-L',
            'title' => 'Large', 'option_values' => ['Size' => 'L'], 'price' => 25, 'stock' => 10,
        ]);
        $small = ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $shirt->id, 'sku' => 'SHIRT-S',
            'title' => 'Small', 'option_values' => ['Size' => 'S'], 'price' => null, 'stock' => 10,
        ]);

        // Own currency: the variant's own price.
        $cad = $this->create(['items' => [['product_id' => $shirt->id, 'product_variant_id' => $large->id, 'quantity' => 1]]]);
        $this->assertSame('25.00', (string) $cad->items->sole()->unit_price);

        // A variant without its own price inherits the product's, so the
        // product's USD price applies.
        $usd = $this->create(['currency' => 'USD', 'items' => [['product_id' => $shirt->id, 'product_variant_id' => $small->id, 'quantity' => 1]]]);
        $this->assertSame('15.00', (string) $usd->items->sole()->unit_price);

        // A variant priced differently from its product has no USD price of
        // its own; the product's would undercharge it.
        $this->expectException(ValidationException::class);
        $this->create(['currency' => 'USD', 'items' => [['product_id' => $shirt->id, 'product_variant_id' => $large->id, 'quantity' => 1]]]);
    }

    public function test_editing_an_order_prices_new_lines_in_the_orders_currency(): void
    {
        $order = $this->create(['currency' => 'USD', 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]]);

        $this->actingAs($this->admin);
        DB::transaction(fn () => app(OrderService::class)->replaceItems($order, [['product_id' => $this->product->id, 'quantity' => 3]]));

        $this->assertSame('11.00', (string) $order->fresh()->items->sole()->unit_price);
    }

    // ------------------------------------------------------------ import

    public function test_import_prices_blank_unit_prices_in_the_order_currency(): void
    {
        $csv = "external_reference,order_date,product_sku,quantity,unit_price,currency\n"
            ."IMP-USD,2026-01-05,MAPLE-1,2,,USD\n"
            ."IMP-CAD,2026-01-05,MAPLE-1,1,,\n"
            ."IMP-GBP,2026-01-05,MAPLE-1,1,,GBP\n";

        $import = new OrdersImport($this->admin);
        Excel::import($import, UploadedFile::fake()->createWithContent('orders.csv', $csv));

        $stats = $import->getStats();
        $this->assertSame(2, $stats['imported']);
        $this->assertSame(1, $stats['failed']);
        $this->assertStringContainsString('Syrup has no price in GBP', $stats['errors'][0]['errors'][0]);

        $usd = Order::where('external_reference', 'IMP-USD')->sole();
        $this->assertSame('USD', $usd->currency);
        $this->assertSame('22.00', (string) $usd->subtotal);

        $cad = Order::where('external_reference', 'IMP-CAD')->sole();
        $this->assertSame('CAD', $cad->currency);
        $this->assertSame('15.00', (string) $cad->subtotal);
    }
}
