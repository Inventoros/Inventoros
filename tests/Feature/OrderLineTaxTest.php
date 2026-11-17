<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\OrdersImport;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreateOrderTool;
use App\Mcp\Tools\GetOrderTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Documents\DocumentPdfService;
use App\Services\OrderService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Per-line tax on sales orders (#416).
 *
 * A line with a tax_rate is taxed by the server: its taxable amount is its
 * net (after its line discount) less its share of the order discount, the
 * tax per rate is rounded once to the cent and split back across that rate's
 * lines, and the order's tax is the sum. A line without a rate keeps the tax
 * amount it was given (imports, API). An order-level tax is the fallback for
 * orders without line rates and is refused alongside them.
 */
final class OrderLineTaxTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $book;

    private Product $gadget;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Tax Org', 'email' => 'tax@org.com', 'currency' => 'EUR', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@linetax.test', 'password' => bcrypt('password'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->book = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'BOOK', 'name' => 'Book', 'price' => 10,
            'currency' => 'EUR', 'stock' => 100, 'min_stock' => 0, 'is_active' => true, 'tax_rate' => 5,
        ]);
        $this->gadget = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'GADGET', 'name' => 'Gadget', 'price' => 20,
            'currency' => 'EUR', 'stock' => 100, 'min_stock' => 0, 'is_active' => true, 'tax_rate' => 27,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $items, array $overrides = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(), 'items' => $items,
        ], $overrides), $this->admin);
    }

    private function assertReconciles(Order $order): void
    {
        $order->refresh();
        $this->assertSame((string) $order->total, Money::add(Money::subtract($order->subtotal, $order->discount_amount), $order->tax, $order->shipping));
        $this->assertSame((string) $order->tax, Money::add('0', ...$order->items->pluck('tax')->all()), 'order tax is the sum of the line taxes');
        foreach ($order->items as $item) {
            $this->assertSame((string) $item->total, Money::add(Money::subtract($item->subtotal, $item->discount_amount), $item->tax));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function webPayload(array $items, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'tax' => 0, 'shipping' => 0, 'items' => $items,
        ], $overrides);
    }

    public function test_the_web_form_taxes_each_line_at_its_own_rate_and_ignores_client_amounts(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), $this->webPayload([
            ['product_id' => $this->book->id, 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 5, 'tax' => 999],
            ['product_id' => $this->gadget->id, 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 27],
        ], ['total' => 1]))->assertSessionHasNoErrors();

        $order = Order::with('items')->sole();
        [$book, $gadget] = $order->items->sortBy('id')->values()->all();

        $this->assertSame('5.000', (string) $book->tax_rate);
        $this->assertSame('1.00', (string) $book->tax);
        $this->assertSame('21.00', (string) $book->total);
        $this->assertSame('27.000', (string) $gadget->tax_rate);
        $this->assertSame('5.40', (string) $gadget->tax);
        $this->assertSame('25.40', (string) $gadget->total);
        $this->assertSame('6.40', (string) $order->tax);
        $this->assertSame('46.40', (string) $order->total);
        $this->assertReconciles($order);
    }

    public function test_line_and_order_discounts_come_off_before_tax(): void
    {
        // Book: 2 x 10 = 20, less a 2.00 line discount = 18 net.
        // Gadget: 1 x 20 = 20 net. Merchandise 38; 10% order discount = 3.80,
        // shared by net: the lines are taxed on 16.20 and 18.00.
        $order = $this->create([
            ['product_id' => $this->book->id, 'quantity' => 2, 'unit_price' => 10, 'discount_type' => 'fixed', 'discount_value' => 2, 'tax_rate' => 5],
            ['product_id' => $this->gadget->id, 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 27],
        ], ['discount_type' => 'percent', 'discount_value' => 10, 'shipping' => 5]);

        [$book, $gadget] = $order->items()->orderBy('id')->get()->all();
        $this->assertSame('0.81', (string) $book->tax);   // 5% of 16.20
        $this->assertSame('4.86', (string) $gadget->tax); // 27% of 18.00

        $order->refresh();
        $this->assertSame('5.80', (string) $order->discount_amount);
        $this->assertSame('5.67', (string) $order->tax);
        // 40 - 5.80 + 5.67 + 5 shipping (never taxed or discounted)
        $this->assertSame('44.87', (string) $order->total);
        $this->assertReconciles($order);

        $this->assertSame([
            ['rate' => '27.000', 'taxable' => '18.00', 'tax' => '4.86'],
            ['rate' => '5.000', 'taxable' => '16.20', 'tax' => '0.81'],
        ], $order->load('items')->taxBreakdown());
    }

    public function test_tax_per_rate_is_rounded_once_then_split_across_its_lines(): void
    {
        // Per-line rounding would charge 3 x 0.03; 27% of 0.30 is 0.081.
        $order = $this->create([
            ['product_id' => $this->gadget->id, 'quantity' => 1, 'unit_price' => 0.10, 'tax_rate' => 27],
            ['product_id' => $this->gadget->id, 'quantity' => 1, 'unit_price' => 0.10, 'tax_rate' => 27],
            ['product_id' => $this->gadget->id, 'quantity' => 1, 'unit_price' => 0.10, 'tax_rate' => 27],
        ]);

        $this->assertSame('0.08', (string) $order->fresh()->tax);
        $this->assertSame(['0.03', '0.02', '0.03'], $order->items()->orderBy('id')->pluck('tax')->map(fn ($tax) => (string) $tax)->all());
        $this->assertReconciles($order);
    }

    public function test_every_currency_rounds_tax_to_the_cent_like_the_other_totals(): void
    {
        // Money keeps two decimals for every currency; a HUF order is no
        // exception: 27% of 999 = 269.73.
        $order = $this->create([['product_id' => $this->gadget->id, 'quantity' => 1, 'unit_price' => 999, 'tax_rate' => 27]], ['currency' => 'HUF']);

        $this->assertSame('269.73', (string) $order->fresh()->tax);
    }

    public function test_a_zero_rate_is_a_rate(): void
    {
        $order = $this->create([['product_id' => $this->book->id, 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0, 'tax' => 3]]);

        $item = $order->items()->sole();
        $this->assertSame('0.000', (string) $item->tax_rate);
        $this->assertSame('0.00', (string) $item->tax);
    }

    public function test_an_order_level_tax_cannot_be_added_to_line_rates(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), $this->webPayload([
            ['product_id' => $this->book->id, 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 5],
        ], ['tax' => 3]))->assertSessionHasErrors('tax');

        $this->assertSame(0, Order::count());
        $this->assertSame(100, $this->book->fresh()->stock);

        $this->expectException(ValidationException::class);
        $this->create([['product_id' => $this->book->id, 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 5]], ['tax' => 3]);
    }

    public function test_an_order_level_tax_alone_works_as_before(): void
    {
        $this->actingAs($this->admin)->post(route('orders.store'), $this->webPayload([
            ['product_id' => $this->book->id, 'quantity' => 2, 'unit_price' => 10],
        ], ['tax' => 3.5]))->assertSessionHasNoErrors();

        $order = Order::with('items')->sole();
        $this->assertSame('3.50', (string) $order->tax);
        $this->assertSame('23.50', (string) $order->total);
        $this->assertNull($order->items->sole()->tax_rate);
        $this->assertSame('0.00', (string) $order->items->sole()->tax);
        $this->assertSame([['rate' => null, 'taxable' => '20.00', 'tax' => '3.50']], $order->taxBreakdown());
    }

    public function test_rest_lines_sent_with_an_amount_keep_it_and_a_rate_wins_over_an_amount(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Api',
            'items' => [
                ['product_id' => $this->book->id, 'quantity' => 1, 'unit_price' => 10, 'tax' => 1.23],
                ['product_id' => $this->gadget->id, 'quantity' => 1, 'unit_price' => 20, 'tax' => 50, 'tax_rate' => 27],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.tax_rate', null)
            ->assertJsonPath('data.items.0.tax', '1.23')
            ->assertJsonPath('data.items.0.net_amount', '10.00')
            ->assertJsonPath('data.items.1.tax_rate', '27.000')
            ->assertJsonPath('data.items.1.tax', '5.40')
            ->assertJsonPath('data.tax', '6.63')
            ->assertJsonPath('data.tax_breakdown.0.rate', '27.000')
            ->assertJsonPath('data.tax_breakdown.1.rate', null)
            ->assertJsonPath('data.tax_breakdown.1.tax', '1.23');

        $this->assertReconciles(Order::sole());
    }

    public function test_rest_validates_the_rate(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        foreach ([101, -1, '5.1234'] as $bad) {
            $this->postJson('/api/v1/orders', [
                'customer_name' => 'Api',
                'items' => [['product_id' => $this->book->id, 'quantity' => 1, 'tax_rate' => $bad]],
            ])->assertUnprocessable()->assertJsonValidationErrors('items.0.tax_rate');
        }

        $this->assertSame(0, Order::count());
    }

    public function test_rest_lines_without_a_rate_are_not_taxed_from_the_product(): void
    {
        // API clients that never sent a rate keep getting the same totals,
        // even for products that now carry one.
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Api',
            'items' => [['product_id' => $this->gadget->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.tax', '0.00')->assertJsonPath('data.total', '20.00');
    }

    public function test_changing_only_line_rates_updates_tax_in_place_without_moving_stock(): void
    {
        $order = $this->create([['product_id' => $this->book->id, 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 5]]);
        $itemId = $order->items()->sole()->id;
        $this->assertSame(98, $this->book->fresh()->stock);

        $this->actingAs($this->admin)->put(route('orders.update', $order), $this->webPayload([
            ['id' => $itemId, 'product_id' => $this->book->id, 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 27, 'tax' => 0],
        ]))->assertSessionHasNoErrors();

        $item = $order->items()->sole();
        $this->assertSame($itemId, $item->id, 'the line was updated, not recreated');
        $this->assertSame('27.000', (string) $item->tax_rate);
        $this->assertSame('5.40', (string) $item->tax);
        $this->assertSame('25.40', (string) $order->fresh()->total);
        $this->assertSame(98, $this->book->fresh()->stock);
        $this->assertReconciles($order);
    }

    public function test_changing_lines_reprices_their_tax(): void
    {
        $order = $this->create([['product_id' => $this->book->id, 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 5]]);

        $this->actingAs($this->admin)->put(route('orders.update', $order), $this->webPayload([
            ['product_id' => $this->book->id, 'quantity' => 4, 'unit_price' => 10, 'tax_rate' => 5],
            ['product_id' => $this->gadget->id, 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 27],
        ]))->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('7.40', (string) $order->tax); // 2.00 + 5.40
        $this->assertSame('67.40', (string) $order->total);
        $this->assertReconciles($order);
    }

    public function test_editing_an_order_with_imported_line_tax_does_not_count_it_twice(): void
    {
        // Lines with tax amounts (as the CSV import and API create them) plus
        // an order-level tax.
        $order = $this->create([
            ['product_id' => $this->book->id, 'quantity' => 1, 'unit_price' => 10, 'tax' => 2],
        ], ['tax' => 3]);
        $this->assertSame('5.00', (string) $order->fresh()->tax);

        $this->actingAs($this->admin)->get(route('orders.edit', $order))
            ->assertInertia(fn ($page) => $page->where('orderLevelTax', '3.00')->where('order.items.0.tax', '2.00'));

        // What the edit form sends back unchanged: the order-level part, and
        // each line's own amount.
        $item = $order->items()->sole();
        $this->actingAs($this->admin)->put(route('orders.update', $order), $this->webPayload([
            ['id' => $item->id, 'product_id' => $this->book->id, 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => null, 'tax' => 2],
        ], ['tax' => 3]))->assertSessionHasNoErrors();

        $this->assertSame('5.00', (string) $order->fresh()->tax);
        $this->assertSame('15.00', (string) $order->fresh()->total);
    }

    public function test_changing_the_order_discount_recomputes_rated_line_tax(): void
    {
        $order = $this->create([['product_id' => $this->gadget->id, 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 27]]);
        $this->assertSame('27.00', (string) $order->fresh()->tax);

        Sanctum::actingAs($this->admin, ['*']);
        $this->putJson("/api/v1/orders/{$order->id}", ['discount_type' => 'percent', 'discount_value' => 10])
            ->assertOk()
            ->assertJsonPath('data.tax', '24.30')
            ->assertJsonPath('data.total', '114.30');

        $this->assertSame('24.30', (string) $order->items()->sole()->tax);
        $this->assertReconciles($order);
    }

    public function test_graphql_accepts_line_rates_and_exposes_net_rate_and_tax(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $query = sprintf(
            'mutation { createOrder(customer_name: "Gql", items: [{product_id: %d, quantity: 2, unit_price: 10, tax_rate: 5}]) { tax total items { net_amount tax_rate tax total } } }',
            $this->book->id,
        );

        $this->postJson('/graphql', ['query' => $query])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.createOrder.tax', 1)
            ->assertJsonPath('data.createOrder.total', 21)
            ->assertJsonPath('data.createOrder.items.0.net_amount', 20)
            ->assertJsonPath('data.createOrder.items.0.tax_rate', 5)
            ->assertJsonPath('data.createOrder.items.0.tax', 1);
    }

    public function test_mcp_create_accepts_rates_and_get_order_shows_them(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(CreateOrderTool::class, [
                'customer_name' => 'Mcp',
                'items' => [['product_id' => $this->gadget->id, 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 27]],
            ])
            ->assertOk();

        $order = Order::sole();
        $this->assertSame('5.40', (string) $order->tax);

        InventorosServer::actingAs($this->admin)
            ->tool(GetOrderTool::class, ['id' => $order->id])
            ->assertOk()
            ->assertSee('"tax_rate":"27.000"')
            ->assertSee('"net_amount":"20.00"')
            ->assertSee('"tax_breakdown":[{"rate":"27.000","taxable":"20.00","tax":"5.40"}]');
    }

    public function test_the_show_page_and_the_invoice_show_net_tax_and_gross(): void
    {
        $order = $this->create([
            ['product_id' => $this->book->id, 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 5],
            ['product_id' => $this->gadget->id, 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 27],
        ]);

        $this->actingAs($this->admin)->get(route('orders.show', $order))
            ->assertInertia(fn ($page) => $page
                ->where('order.items.0.tax_rate', '5.000')
                ->where('order.items.0.net_amount', '20.00')
                ->where('order.items.0.total', '21.00')
                ->where('order.tax_breakdown.0.rate', '27.000')
                ->where('order.tax_breakdown.1.tax', '1.00'));

        $html = view('pdf.invoice', app(DocumentPdfService::class)->orderInvoiceViewData($order->fresh()))->render();
        $this->assertStringContainsString('<th style="width: 15%;">Net</th>', $html);
        $this->assertStringContainsString('<th style="width: 15%;">Gross</th>', $html);
        $this->assertStringContainsString('Tax 27% on EUR 20.00', $html);
        $this->assertStringContainsString('Tax 5% on EUR 20.00', $html);
        $this->assertStringContainsString('EUR 25.40', $html);
    }

    public function test_an_invoice_without_line_tax_keeps_its_layout(): void
    {
        $order = $this->create([['product_id' => $this->book->id, 'quantity' => 1, 'unit_price' => 10]], ['tax' => 2]);

        $html = view('pdf.invoice', app(DocumentPdfService::class)->orderInvoiceViewData($order->fresh()))->render();
        $this->assertStringContainsString('<th style="width: 20%;">Amount</th>', $html);
        $this->assertStringNotContainsString('Gross</th>', $html);
        $this->assertStringContainsString('<td>Tax</td>', $html);
    }

    public function test_the_csv_order_import_keeps_line_tax_amounts_without_a_rate(): void
    {
        $csv = "external_reference,order_date,product_sku,quantity,unit_price,line_tax\n"
            ."IMP-1,2026-01-05,BOOK,1,10,1.50\n"
            ."IMP-1,2026-01-05,GADGET,1,20,5.40\n";

        $import = new OrdersImport($this->admin);
        Excel::import($import, UploadedFile::fake()->createWithContent('orders.csv', $csv));

        $this->assertSame(1, $import->getStats()['imported']);
        $order = Order::with('items')->sole();
        $this->assertSame('6.90', (string) $order->tax);
        $this->assertTrue($order->items->every(fn ($item) => $item->tax_rate === null));
        $this->assertSame(['1.50', '5.40'], $order->items->sortBy('id')->pluck('tax')->map(fn ($tax) => (string) $tax)->values()->all());
    }
}
