<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InvalidStateException;
use App\Mail\PurchaseOrderEmail;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreatePurchaseOrderTool;
use App\Mcp\Tools\GetPurchaseOrderTool;
use App\Models\Auth\Organization;
use App\Models\ExchangeRates\ExchangeRate;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\SupplierPriceHistory;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use App\Services\Documents\DocumentPdfService;
use App\Services\PurchaseOrderEmailService;
use App\Services\PurchaseOrderService;
use App\Services\ReorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\ExchangeRates\ExchangeRateFixtures;
use Tests\TestCase;

/**
 * Per-line tax on purchase orders (#416, part 2).
 *
 * The sales order rules (App\Support\LineTax) on lines whose net is quantity
 * x unit cost: a rated line's tax is computed by the server, per rate, and a
 * line without a rate keeps the amount it was given. The PO's tax is the sum
 * of the line taxes plus the PO-level tax, which is the fallback for POs
 * without rates and is refused alongside them. Tax flows into the exchange
 * rate snapshot's converted totals; unit costs (and so receiving) stay net.
 */
final class PurchaseOrderLineTaxTest extends TestCase
{
    use ExchangeRateFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Supplier $hufSupplier;

    private Supplier $eurSupplier;

    private Product $book;

    private Product $gadget;

    protected function setUp(): void
    {
        parent::setUp();

        $this->atTime();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();

        // A HUF organization buying books (5% VAT) and other goods (27%).
        $this->org = $this->organization();
        $this->admin = $this->user($this->org);
        $this->hufSupplier = $this->supplier($this->org, 'HUF');
        $this->eurSupplier = $this->supplier($this->org, 'EUR');
        $this->book = $this->product($this->org);
        $this->book->update(['name' => 'Book', 'tax_rate' => 5]);
        $this->gadget = $this->product($this->org);
        $this->gadget->update(['name' => 'Gadget', 'tax_rate' => 27]);
    }

    protected function tearDown(): void
    {
        $this->tearDownExchangeRates();
        parent::tearDown();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $items, array $overrides = []): array
    {
        return $overrides + [
            'supplier_id' => $this->hufSupplier->id,
            'order_date' => '2026-11-16',
            'currency' => 'HUF',
            'tax' => 0,
            'shipping' => 0,
            'items' => $items,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $items, array $overrides = []): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->create($this->org->id, $this->admin, $this->payload($items, $overrides));
    }

    /**
     * 3 books at 10.00 (5%) and 2 gadgets at 100.00 (27%).
     *
     * @return array<int, array<string, mixed>>
     */
    private function mixedLines(): array
    {
        return [
            ['product_id' => $this->book->id, 'quantity' => 3, 'unit_cost' => '10.00', 'tax_rate' => 5],
            ['product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => '100.00', 'tax_rate' => 27],
        ];
    }

    private function assertReconciles(PurchaseOrder $po): void
    {
        $po->load('items');
        foreach ($po->items as $item) {
            $this->assertSame(bcadd($item->subtotal, $item->tax, 2), $item->total, "Line {$item->id} total is subtotal + tax.");
        }
        $this->assertSame(bcadd(bcadd($po->subtotal, $po->tax, 2), $po->shipping, 2), $po->total);
    }

    public function test_line_rates_are_validated(): void
    {
        $this->actingAs($this->admin);

        foreach ([101, -1, 'abc', '5.1234'] as $bad) {
            $this->post(route('purchase-orders.store'), $this->payload([
                ['product_id' => $this->book->id, 'quantity' => 1, 'unit_cost' => '10.00', 'tax_rate' => $bad],
            ]))->assertSessionHasErrors('items.0.tax_rate');
        }
        $this->assertSame(0, PurchaseOrder::count());

        foreach ([null, 0, '27', '5.5', '9.375', 100] as $good) {
            $this->post(route('purchase-orders.store'), $this->payload([
                ['product_id' => $this->book->id, 'quantity' => 1, 'unit_cost' => '10.00', 'tax_rate' => $good],
            ]))->assertSessionHasNoErrors();
        }
        $this->assertSame(6, PurchaseOrder::count());

        // 0 is a real rate, not "no rate".
        $zero = PurchaseOrder::with('items')->orderBy('id')->skip(1)->firstOrFail();
        $this->assertSame('0.000', $zero->items->first()->tax_rate);
        $none = PurchaseOrder::with('items')->orderBy('id')->firstOrFail();
        $this->assertNull($none->items->first()->tax_rate);
    }

    public function test_the_forms_get_each_products_rate_to_prefill_new_lines(): void
    {
        $this->gadget->update(['has_variants' => true]);
        ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $this->gadget->id, 'sku' => 'GADGET-RED',
            'title' => 'Red', 'option_values' => ['Color' => 'Red'], 'stock' => 0, 'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('PurchaseOrders/Create')
                ->where('products', fn ($products) => collect($products)->firstWhere('id', $this->book->id)['tax_rate'] === '5.000'
                    && collect($products)->firstWhere('id', $this->gadget->id)['tax_rate'] === '27.000'
                    // Variants take the product's rate: they carry none of their own.
                    && ! array_key_exists('tax_rate', collect($products)->firstWhere('id', $this->gadget->id)['variants'][0])));
    }

    public function test_the_server_computes_rated_line_tax_and_ignores_a_sent_amount(): void
    {
        $po = $this->create([
            ['product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => '100.00', 'tax_rate' => 27, 'tax' => '999.00'],
        ]);

        $line = $po->items()->firstOrFail();
        $this->assertSame('27.000', $line->tax_rate);
        $this->assertSame('54.00', $line->tax);
        $this->assertSame('254.00', $line->total);
        $this->assertSame('54.00', $po->tax);
        $this->assertSame('254.00', $po->total);
        $this->assertReconciles($po);
    }

    public function test_mixed_rates_give_per_rate_totals_rounded_once_per_rate(): void
    {
        $po = $this->create([
            ...$this->mixedLines(),
            // Two 5% lines of 0.10: 0.005 each would round to 0.01 twice, but
            // 5% of their 0.20 together is 0.01.
            ['product_id' => $this->book->id, 'quantity' => 1, 'unit_cost' => '0.10', 'tax_rate' => 5],
            ['product_id' => $this->book->id, 'quantity' => 1, 'unit_cost' => '0.10', 'tax_rate' => 5],
        ], ['shipping' => '15.00']);

        // 5% of 30.20 = 1.51; 27% of 200.00 = 54.00. Shipping is not taxed.
        $this->assertSame('230.20', $po->subtotal);
        $this->assertSame('55.51', $po->tax);
        $this->assertSame('300.71', $po->total);
        $this->assertReconciles($po);

        $po->load('items');
        $this->assertSame(['1.50', '54.00', '0.01', '0.00'], $po->items->sortBy('id')->pluck('tax')->values()->all());

        $breakdown = $po->taxBreakdown();
        $this->assertSame([
            ['rate' => '27.000', 'taxable' => '200.00', 'tax' => '54.00', 'converted_taxable' => '200.00', 'converted_tax' => '54.00'],
            ['rate' => '5.000', 'taxable' => '30.20', 'tax' => '1.51', 'converted_taxable' => '30.20', 'converted_tax' => '1.51'],
        ], $breakdown);
    }

    public function test_the_po_level_tax_is_the_fallback_without_line_rates(): void
    {
        $po = $this->create([
            ['product_id' => $this->book->id, 'quantity' => 3, 'unit_cost' => '10.00'],
        ], ['tax' => '2.50', 'shipping' => '5.00']);

        $this->assertSame('2.50', $po->tax);
        $this->assertSame('37.50', $po->total);
        $this->assertNull($po->items()->first()->tax_rate);
        $this->assertSame('0.00', $po->items()->first()->tax);
        $this->assertSame('2.50', $po->documentLevelTax());
        $this->assertSame([['rate' => null, 'taxable' => '30.00', 'tax' => '2.50', 'converted_taxable' => '30.00', 'converted_tax' => '2.50']], $po->load('items')->taxBreakdown());
    }

    public function test_a_po_level_tax_is_refused_alongside_line_rates(): void
    {
        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload($this->mixedLines(), ['tax' => '10.00']))
            ->assertSessionHasErrors('tax');
        $this->assertSame(0, PurchaseOrder::count());

        $po = $this->create($this->mixedLines());

        // A header-only tax edit on a rated PO is refused too.
        try {
            app(PurchaseOrderService::class)->update($po, $this->org->id, ['tax' => '10.00'], $this->admin);
            $this->fail('A PO-level tax was accepted alongside line rates.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tax', $e->errors());
        }
        $this->assertSame('55.50', $po->fresh()->tax);

        // Zero is fine, and keeps the line taxes.
        $po = app(PurchaseOrderService::class)->update($po, $this->org->id, ['tax' => 0, 'shipping' => '3.00'], $this->admin);
        $this->assertSame('55.50', $po->tax);
        $this->assertSame('288.50', $po->total);
    }

    public function test_editing_lines_recomputes_tax_and_lines_without_tax_keys_keep_theirs(): void
    {
        $po = $this->create($this->mixedLines());
        [$book, $gadget] = $po->items()->orderBy('id')->get()->all();

        // An API client that only changes quantities keeps the rates.
        $po = app(PurchaseOrderService::class)->update($po, $this->org->id, ['items' => [
            ['id' => $book->id, 'product_id' => $this->book->id, 'quantity' => 4, 'unit_cost' => '10.00'],
            ['id' => $gadget->id, 'product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => '100.00'],
        ]], $this->admin);
        $this->assertSame('56.00', $po->tax);
        $this->assertSame('5.000', $book->fresh()->tax_rate);
        $this->assertSame('2.00', $book->fresh()->tax);

        // Clearing a rate drops that line's tax; a new line is untaxed unless it sends a rate.
        $po = app(PurchaseOrderService::class)->update($po, $this->org->id, ['items' => [
            ['id' => $book->id, 'product_id' => $this->book->id, 'quantity' => 4, 'unit_cost' => '10.00', 'tax_rate' => null],
            ['id' => $gadget->id, 'product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => '100.00', 'tax_rate' => 27],
            ['product_id' => $this->book->id, 'quantity' => 1, 'unit_cost' => '20.00'],
        ]], $this->admin);
        $this->assertNull($book->fresh()->tax_rate);
        $this->assertSame('0.00', $book->fresh()->tax);
        $this->assertSame('54.00', $po->tax);
        $this->assertSame('314.00', $po->total);
        $this->assertReconciles($po);
    }

    public function test_the_edit_form_starts_from_the_po_level_tax_only(): void
    {
        $rated = $this->create($this->mixedLines());
        $legacy = $this->create([['product_id' => $this->book->id, 'quantity' => 3, 'unit_cost' => '10.00']], ['tax' => '4.00']);

        $this->actingAs($this->admin)
            ->get(route('purchase-orders.edit', $rated))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('documentLevelTax', '0.00')
                ->where('purchaseOrder.tax', '55.50')
                ->where('purchaseOrder.items.0.tax_rate', '5.000'));
        $this->get(route('purchase-orders.edit', $legacy))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('documentLevelTax', '4.00'));

        // Saving the form unchanged (rated lines, PO-level tax 0) keeps the totals.
        $rated->load('items');
        $this->put(route('purchase-orders.update', $rated), [
            'supplier_id' => $this->hufSupplier->id, 'order_date' => '2026-11-16', 'currency' => 'HUF',
            'tax' => 0, 'shipping' => 0,
            'items' => $rated->items->map(fn ($item) => [
                'id' => $item->id, 'product_id' => $item->product_id, 'quantity' => $item->quantity_ordered,
                'unit_cost' => $item->unit_cost, 'tax_rate' => $item->tax_rate, 'tax' => $item->tax,
            ])->all(),
        ])->assertSessionHasNoErrors();
        $this->assertSame('55.50', $rated->fresh()->tax);
        $this->assertSame('285.50', $rated->fresh()->total);
    }

    public function test_converted_totals_include_line_tax_while_draft_and_stay_put_once_locked(): void
    {
        $this->fakeFrankfurter(400);
        $po = $this->create($this->mixedLines(), ['supplier_id' => $this->eurSupplier->id, 'currency' => 'EUR', 'shipping' => '10.00']);

        // 230 EUR + 55.50 EUR tax + 10 EUR shipping, at 400 HUF.
        $this->assertSame('400.0000000000', $po->exchange_rate);
        $this->assertSame('92000.00', $po->converted_subtotal);
        $this->assertSame('22200.00', $po->converted_tax);
        $this->assertSame('4000.00', $po->converted_shipping);
        $this->assertSame('118200.00', $po->converted_total);
        $this->assertSame('118200.00', $po->organizationCurrencyTotal());
        $this->assertSame(['21600.00', '600.00'], array_column($po->load('items')->taxBreakdown(), 'converted_tax'));

        // A draft edit that changes a line rate moves the converted tax and total.
        $items = $po->items()->orderBy('id')->get();
        $po = app(PurchaseOrderService::class)->update($po, $this->org->id, ['items' => [
            ['id' => $items[0]->id, 'product_id' => $this->book->id, 'quantity' => 3, 'unit_cost' => '10.00', 'tax_rate' => 5],
            ['id' => $items[1]->id, 'product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => '100.00', 'tax_rate' => 18],
        ]], $this->admin);
        $this->assertSame('37.50', $po->tax);
        $this->assertSame('15000.00', $po->converted_tax);
        $this->assertSame('111000.00', $po->converted_total);

        app(PurchaseOrderEmailService::class)->markSent($po, $this->admin);
        $po->refresh();
        $this->assertNotNull($po->exchange_rate_locked_at);

        // Later rates and edit attempts leave the locked snapshot alone.
        ExchangeRate::query()->update(['rate' => '450']);
        try {
            app(PurchaseOrderService::class)->update($po, $this->org->id, ['items' => [
                ['id' => $items[1]->id, 'product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => '100.00', 'tax_rate' => 27],
            ]], $this->admin);
            $this->fail('A sent PO was edited.');
        } catch (InvalidStateException) {
        }
        $po->refresh();
        $this->assertSame('37.50', $po->tax);
        $this->assertSame('15000.00', $po->converted_tax);
        $this->assertSame('111000.00', $po->converted_total);
        $this->assertSame(['14400.00', '600.00'], array_column($po->load('items')->taxBreakdown(), 'converted_tax'));
    }

    public function test_the_approval_threshold_still_compares_the_total_including_tax(): void
    {
        $this->fakeFrankfurter(400);
        $this->org->update(['settings' => ['approvals' => ['purchase_orders_enabled' => true, 'purchase_orders_threshold' => 100000]]]);
        $eur = ['supplier_id' => $this->eurSupplier->id, 'currency' => 'EUR'];

        // 200 EUR net = 80,000 HUF: under the threshold.
        $net = $this->create([['product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => '100.00']], $eur);
        $this->assertFalse($net->fresh()->needsApproval());

        // The same 54 EUR of tax entered for the whole PO (as before line
        // rates) or worked out from a 27% line rate gives the same total,
        // 254 EUR = 101,600 HUF, and the same decision: over the threshold.
        $poLevel = $this->create([['product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => '100.00']], $eur + ['tax' => '54.00']);
        $lineRate = $this->create([['product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => '100.00', 'tax_rate' => 27]], $eur);

        $this->assertSame($poLevel->fresh()->converted_total, $lineRate->fresh()->converted_total);
        $this->assertSame('101600.00', $lineRate->fresh()->organizationCurrencyTotal());
        $this->assertTrue($poLevel->fresh()->needsApproval());
        $this->assertTrue($lineRate->fresh()->needsApproval());
    }

    public function test_receiving_records_the_net_unit_cost(): void
    {
        $this->fakeFrankfurter(400);
        $this->gadget->update(['purchase_price' => '3000.00']);
        $po = $this->create([
            ['product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => '100.00', 'tax_rate' => 27],
        ], ['supplier_id' => $this->eurSupplier->id, 'currency' => 'EUR']);
        $local = $this->create([
            ['product_id' => $this->book->id, 'quantity' => 2, 'unit_cost' => '1000.00', 'tax_rate' => 5],
        ]);

        foreach ([$po, $local] as $order) {
            app(PurchaseOrderEmailService::class)->markSent($order, $this->admin);
            $line = $order->items()->firstOrFail();
            $this->actingAs($this->admin);
            app(PurchaseOrderService::class)->receive($order->fresh(), $this->admin, [['id' => $line->id, 'quantity_to_receive' => 2]]);
        }

        // 100 EUR net at 400 = 40,000 HUF, not the 127 EUR gross.
        $entry = SupplierPriceHistory::withoutGlobalScopes()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame('40000.00', $entry->cost_price);
        $this->assertSame('100.00', $entry->source_cost_price);

        $entry = SupplierPriceHistory::withoutGlobalScopes()->where('purchase_order_id', $local->id)->firstOrFail();
        $this->assertSame('1000.00', $entry->cost_price);

        $this->assertSame('3000.00', $this->gadget->fresh()->purchase_price);
        $this->assertSame(2, (int) $this->gadget->fresh()->stock);
    }

    public function test_rest_accepts_and_returns_line_tax(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/api/v1/purchase-orders', $this->payload([
            ...$this->mixedLines(),
            // A line without a rate keeps the amount it sends.
            ['product_id' => $this->book->id, 'quantity' => 1, 'unit_cost' => '20.00', 'tax' => '1.25'],
        ]))->assertCreated();

        $response
            ->assertJsonPath('data.tax', '56.75')
            ->assertJsonPath('data.total', '306.75')
            ->assertJsonPath('data.items.0.tax_rate', '5.000')
            ->assertJsonPath('data.items.0.tax', '1.50')
            ->assertJsonPath('data.items.1.tax_rate', '27.000')
            ->assertJsonPath('data.items.1.total', '254.00')
            ->assertJsonPath('data.items.2.tax_rate', null)
            ->assertJsonPath('data.items.2.tax', '1.25')
            ->assertJsonPath('data.tax_breakdown.0.rate', '27.000')
            ->assertJsonPath('data.tax_breakdown.1.rate', '5.000')
            ->assertJsonPath('data.tax_breakdown.2.rate', null)
            ->assertJsonPath('data.tax_breakdown.2.tax', '1.25');

        $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['product_id' => $this->book->id, 'quantity' => 1, 'unit_cost' => '1.00', 'tax_rate' => 120],
        ]))->assertUnprocessable()->assertJsonValidationErrors('items.0.tax_rate');

        $this->postJson('/api/v1/purchase-orders', $this->payload($this->mixedLines(), ['tax' => '3.00']))
            ->assertUnprocessable()->assertJsonValidationErrors('tax');

        // A header-only REST update leaves the line taxes alone.
        $id = $response->json('data.id');
        $this->putJson("/api/v1/purchase-orders/{$id}", ['shipping' => '4.00'])
            ->assertOk()
            ->assertJsonPath('data.tax', '56.75')
            ->assertJsonPath('data.total', '310.75');
    }

    public function test_graphql_accepts_and_returns_line_tax(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/graphql', [
            'query' => 'mutation($items: [PurchaseOrderItemInput!]!) { createPurchaseOrder(supplier_id: '.$this->hufSupplier->id.', order_date: "2026-11-16", currency: "HUF", items: $items) { id tax total items { tax_rate tax total } } }',
            'variables' => ['items' => [
                ['product_id' => $this->book->id, 'quantity' => 3, 'unit_cost' => 10, 'tax_rate' => 5, 'tax' => 99],
                ['product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => 100, 'tax_rate' => 27],
            ]],
        ])->assertOk();

        $this->assertNull($response->json('errors'));
        $po = $response->json('data.createPurchaseOrder');
        $this->assertEquals(55.5, $po['tax']);
        $this->assertEquals(285.5, $po['total']);
        $this->assertEquals(5, $po['items'][0]['tax_rate']);
        $this->assertEquals(1.5, $po['items'][0]['tax']);
        $this->assertEquals(254, $po['items'][1]['total']);

        $invalid = $this->postJson('/graphql', [
            'query' => 'mutation($items: [PurchaseOrderItemInput!]!) { createPurchaseOrder(supplier_id: '.$this->hufSupplier->id.', order_date: "2026-11-16", currency: "HUF", items: $items) { id } }',
            'variables' => ['items' => [['product_id' => $this->book->id, 'quantity' => 1, 'unit_cost' => 1, 'tax_rate' => 101]]],
        ]);
        $this->assertNotEmpty($invalid->json('errors'));
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_mcp_tools_accept_and_return_line_tax(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(CreatePurchaseOrderTool::class, $this->payload($this->mixedLines()))
            ->assertOk()
            ->assertSee(['55.50', '285.50', 'tax_breakdown']);

        $po = PurchaseOrder::firstOrFail();
        $this->assertSame('55.50', $po->tax);
        $this->assertSame('27.000', $po->items()->orderByDesc('id')->first()->tax_rate);

        InventorosServer::actingAs($this->admin)
            ->tool(GetPurchaseOrderTool::class, ['id' => $po->id])
            ->assertOk()
            ->assertSee(['tax_rate', '27.000', '54.00', 'tax_breakdown']);

        // No rate is taken from the product for a client that sends none.
        InventorosServer::actingAs($this->admin)
            ->tool(CreatePurchaseOrderTool::class, $this->payload([['product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => '100.00']]))
            ->assertOk();
        $plain = PurchaseOrder::latest('id')->firstOrFail();
        $this->assertSame('0.00', $plain->tax);
        $this->assertNull($plain->items()->first()->tax_rate);
    }

    public function test_older_pos_and_clients_without_rates_keep_their_totals(): void
    {
        $legacy = $this->legacyPurchaseOrder($this->org, $this->hufSupplier, $this->book, 'HUF', PurchaseOrder::STATUS_DRAFT);
        $legacy->update(['tax' => '3.00', 'total' => '53.00']);
        $line = $legacy->items()->firstOrFail();

        $this->assertNull($line->tax_rate);
        $this->assertSame('3.00', $legacy->documentLevelTax());
        $this->assertSame([['rate' => null, 'taxable' => '50.00', 'tax' => '3.00', 'converted_taxable' => '50.00', 'converted_tax' => '3.00']], $legacy->load('items')->taxBreakdown());

        // Edited by a client that knows nothing of rates: same totals.
        $legacy = app(PurchaseOrderService::class)->update($legacy, $this->org->id, ['items' => [
            ['id' => $line->id, 'product_id' => $this->book->id, 'quantity' => 5, 'unit_cost' => '10.00'],
        ]], $this->admin);
        $this->assertSame('3.00', $legacy->tax);
        $this->assertSame('53.00', $legacy->total);
        $this->assertNull($line->fresh()->tax_rate);

        // Product rates are never applied to a client that sends none.
        $po = $this->create([['product_id' => $this->gadget->id, 'quantity' => 2, 'unit_cost' => '100.00']], ['tax' => '7.00']);
        $this->assertSame('7.00', $po->tax);
        $this->assertSame('207.00', $po->total);

        // Automatic and quick reorders stay untaxed too.
        $this->gadget->suppliers()->attach($this->hufSupplier->id, ['cost_price' => '100.00', 'is_primary' => true]);
        $reorder = app(ReorderService::class)->createDraftPurchaseOrder($this->org->id, $this->hufSupplier->id, [$this->gadget->fresh()], $this->admin->id, 'Reorder', 'quick_reorder', 'Quick reorder');
        $this->assertSame('0.00', $reorder->tax);
        $this->assertNull($reorder->items()->first()->tax_rate);
    }

    public function test_the_pdf_and_supplier_email_show_line_tax_and_tax_per_rate(): void
    {
        $this->fakeFrankfurter(400);
        $po = $this->create($this->mixedLines(), ['supplier_id' => $this->eurSupplier->id, 'currency' => 'EUR']);

        $html = view('pdf.purchase-order-invoice', app(DocumentPdfService::class)->purchaseOrderViewData($po->fresh()))->render();
        $this->assertStringContainsString('<th style="width: 15%;">Gross</th>', $html);
        $this->assertStringContainsString('27%', $html);
        $this->assertStringContainsString('Tax 27% on EUR 200.00', $html);
        $this->assertStringContainsString('Tax 5% on EUR 30.00', $html);
        $this->assertStringContainsString('EUR 254.00', $html);
        $this->assertStringContainsString('Tax 27% in HUF', $html);
        $this->assertStringContainsString('HUF 21,600.00', $html);
        $this->assertStringContainsString('HUF 114,200.00', $html);
        // The PDF renders.
        $this->assertNotEmpty(app(DocumentPdfService::class)->purchaseOrder($po->fresh())->output());

        $plain = $this->create([['product_id' => $this->book->id, 'quantity' => 1, 'unit_cost' => '10.00']], ['tax' => '1.00']);
        $html = view('pdf.purchase-order-invoice', app(DocumentPdfService::class)->purchaseOrderViewData($plain->fresh()))->render();
        $this->assertStringNotContainsString('Gross</th>', $html);
        $this->assertStringContainsString('<td>Tax</td>', $html);

        $mail = new PurchaseOrderEmail($po->fresh(['items', 'supplier']));
        $rendered = $mail->render();
        $this->assertStringContainsString('Subtotal (before tax):', $rendered);
        $this->assertStringContainsString('Tax 27% on 200.00:', $rendered);
        $this->assertStringContainsString('285.50 EUR', $rendered);
    }

    public function test_the_show_page_gets_the_tax_breakdown(): void
    {
        $po = $this->create($this->mixedLines());

        $this->actingAs($this->admin)
            ->get(route('purchase-orders.show', $po))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('PurchaseOrders/Show')
                ->where('taxBreakdown.0.rate', '27.000')
                ->where('taxBreakdown.0.tax', '54.00')
                ->where('taxBreakdown.1.rate', '5.000')
                ->where('purchaseOrder.items.1.tax_rate', '27.000'));
    }

    public function test_line_tax_edits_stay_inside_the_organization(): void
    {
        $other = $this->organization('Other Org', 'HUF');
        $otherAdmin = $this->user($other);
        $otherSupplier = $this->supplier($other, 'HUF');
        $otherProduct = $this->product($other);
        $foreign = app(PurchaseOrderService::class)->create($other->id, $otherAdmin, [
            'supplier_id' => $otherSupplier->id, 'order_date' => '2026-11-16', 'currency' => 'HUF',
            'items' => [['product_id' => $otherProduct->id, 'quantity' => 1, 'unit_cost' => '100.00', 'tax_rate' => 27]],
        ]);
        $foreignLine = $foreign->items()->firstOrFail();

        $po = $this->create($this->mixedLines());

        // Naming another organization's line id adds a new line here and
        // leaves that line (and its tax) alone.
        app(PurchaseOrderService::class)->update($po, $this->org->id, ['items' => [
            ['id' => $foreignLine->id, 'product_id' => $this->book->id, 'quantity' => 1, 'unit_cost' => '10.00', 'tax_rate' => 0],
        ]], $this->admin);

        $this->assertSame('27.000', $foreignLine->fresh()->tax_rate);
        $this->assertSame('27.00', $foreignLine->fresh()->tax);
        $this->assertSame('27.00', $foreign->fresh()->tax);
        $this->assertSame(1, $po->items()->count());

        $this->actingAs($this->admin)->get(route('purchase-orders.edit', $foreign))->assertNotFound();
    }
}
