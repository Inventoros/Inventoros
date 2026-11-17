<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Exports\ProductsExport;
use App\Imports\ProductsImport;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\GetProductTool;
use App\Models\Auth\Organization;
use App\Models\ExchangeRates\ExchangeRate;
use App\Models\ExchangeRates\ExchangeRateSetting;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\SupplierPriceHistory;
use App\Models\User;
use App\Services\ExchangeRates\ExchangeRateService;
use App\Services\ProductService;
use App\Services\ReorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #429: a supplier's cost is in the supplier's currency, not the
 * product's. A HUF organization with a HUF product bought from a EUR
 * supplier: costs and their history show in EUR, with the amount in HUF at
 * the organization's stored rate beside them, never at an invented one.
 */
final class SupplierCostCurrencyTest extends TestCase
{
    use ExchangeRateFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Supplier $eurSupplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->atTime();
        Mail::fake();
        Notification::fake();
        // Viewing a product never asks a provider for a rate.
        Http::preventStrayRequests();

        $this->org = $this->organization();
        $this->admin = $this->user($this->org);
        $this->eurSupplier = $this->supplier($this->org, 'EUR');
        $this->product = $this->product($this->org, 'HUF');
    }

    protected function tearDown(): void
    {
        $this->tearDownExchangeRates();
        parent::tearDown();
    }

    private function storeRate(string $rate, string $date, string $from = 'EUR', string $to = 'HUF'): void
    {
        ExchangeRate::create([
            'provider' => 'frankfurter', 'base_currency' => $from, 'quote_currency' => $to,
            'rate' => $rate, 'effective_date' => $date, 'retrieved_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function linkSuppliers(array $rows, ?Product $product = null): void
    {
        $this->actingAs($this->admin);
        app(ProductService::class)->syncSuppliers($product ?? $this->product, $rows);
    }

    private function link(?Product $product = null, ?Supplier $supplier = null): object
    {
        return DB::table('product_supplier')
            ->where('product_id', ($product ?? $this->product)->id)
            ->where('supplier_id', ($supplier ?? $this->eurSupplier)->id)
            ->sole();
    }

    // ---------------------------------------------------------------- product page

    public function test_the_product_page_shows_a_supplier_cost_in_its_currency_and_converted_at_the_stored_rate(): void
    {
        $this->storeRate('390.25', '2026-11-16');
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50', 'is_primary' => true]]);

        $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Show')
                ->where('product.currency', 'HUF')
                ->where('product.suppliers.0.pivot.cost_price', '12.50')
                ->where('product.suppliers.0.pivot.cost_currency', 'EUR')
                ->where("supplierCosts.{$this->eurSupplier->id}.currency", 'EUR')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.currency", 'HUF')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.status", 'ok')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.amount", '4878.13')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.rate", '390.25')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.effective_date", '2026-11-16')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.is_fallback", false)
            );
    }

    public function test_without_a_stored_rate_the_cost_keeps_its_currency_and_says_there_is_no_rate(): void
    {
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50']]);

        $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("supplierCosts.{$this->eurSupplier->id}.currency", 'EUR')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.status", 'unavailable')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.amount", null)
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.rate", null)
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.currency", 'HUF')
            );

        Http::assertNothingSent();
    }

    public function test_a_rate_older_than_the_freshness_window_is_marked_stale(): void
    {
        $this->storeRate('380', '2026-11-02');
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '10.00']]);

        $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))
            ->assertInertia(fn (Assert $page) => $page
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.status", 'stale')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.amount", '3800.00')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.effective_date", '2026-11-02')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.age_days", 14)
            );
    }

    public function test_a_cost_in_the_organization_currency_is_not_converted(): void
    {
        $hufSupplier = $this->supplier($this->org, 'HUF');
        $this->linkSuppliers([['supplier_id' => $hufSupplier->id, 'cost_price' => '2900']]);

        $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))
            ->assertInertia(fn (Assert $page) => $page
                ->where("supplierCosts.{$hufSupplier->id}.currency", 'HUF')
                ->where("supplierCosts.{$hufSupplier->id}.converted", null)
            );
    }

    public function test_another_organizations_rates_are_never_used(): void
    {
        $other = $this->organization('Other Org');
        ExchangeRateSetting::forOrganization($other->id)->update(['provider' => 'manual']);
        app(ExchangeRateService::class)->storeManual($other->id, 'EUR', '400', '2026-11-16', null);
        ExchangeRateSetting::forOrganization($this->org->id)->update(['provider' => 'manual']);

        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '10.00']]);

        $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))
            ->assertInertia(fn (Assert $page) => $page
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.status", 'unavailable')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.amount", null)
            );

        app(ExchangeRateService::class)->storeManual($this->org->id, 'EUR', '391', '2026-11-16', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))
            ->assertInertia(fn (Assert $page) => $page
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.status", 'ok')
                ->where("supplierCosts.{$this->eurSupplier->id}.converted.amount", '3910.00')
            );
    }

    // ---------------------------------------------------------------- link currency

    public function test_a_new_link_takes_the_supplier_currency_and_an_explicit_currency_is_kept(): void
    {
        $usdSupplier = $this->supplier($this->org, 'USD');

        $this->actingAs($this->admin)
            ->put(route('products.update', $this->product), [
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'price' => 9000,
                'currency' => 'HUF',
                'stock' => 0,
                'min_stock' => 0,
                'suppliers' => [
                    ['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50', 'is_primary' => true],
                    ['supplier_id' => $usdSupplier->id, 'cost_price' => '14.00', 'cost_currency' => 'eur'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('EUR', $this->link()->cost_currency);
        $this->assertSame('EUR', $this->link(supplier: $usdSupplier)->cost_currency);
    }

    public function test_changing_a_suppliers_currency_does_not_re_denominate_existing_costs(): void
    {
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50', 'is_primary' => true]]);

        $this->eurSupplier->update(['currency' => 'USD']);

        // Saving the product again without naming a currency keeps the link's.
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50', 'is_primary' => true]]);

        $this->assertSame('EUR', $this->link()->cost_currency);
        $this->assertSame(1, SupplierPriceHistory::where('product_id', $this->product->id)->count());

        $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.suppliers.0.currency', 'USD')
                ->where("supplierCosts.{$this->eurSupplier->id}.currency", 'EUR')
            );
    }

    public function test_changing_only_the_cost_currency_is_recorded_in_the_price_history(): void
    {
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50']]);
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50', 'cost_currency' => 'USD']]);

        $history = SupplierPriceHistory::where('product_id', $this->product->id)->orderBy('id')->get();

        $this->assertSame(['EUR', 'USD'], $history->pluck('currency')->all());
        $this->assertSame('USD', $this->link()->cost_currency);
    }

    public function test_the_cost_currency_is_validated(): void
    {
        foreach (['EURO', 'E1', '12€'] as $bad) {
            $this->actingAs($this->admin)
                ->put(route('products.update', $this->product), [
                    'name' => $this->product->name,
                    'sku' => $this->product->sku,
                    'price' => 9000,
                    'stock' => 0,
                    'min_stock' => 0,
                    'suppliers' => [['supplier_id' => $this->eurSupplier->id, 'cost_price' => '1', 'cost_currency' => $bad]],
                ])
                ->assertSessionHasErrors('suppliers.0.cost_currency');
        }
    }

    // ---------------------------------------------------------------- price history

    public function test_price_history_rows_show_their_own_currency_and_are_converted_at_their_dates_rate(): void
    {
        $this->storeRate('385', '2026-11-10');

        $legacyPo = $this->legacyPurchaseOrder($this->org, $this->eurSupplier, $this->product, 'EUR');
        $rows = [
            // Recorded on a day with a stored rate.
            'rated' => ['cost_price' => '11.00', 'currency' => 'EUR', 'recorded_at' => '2026-11-10 09:00:00'],
            // Before any stored rate: shown as it is, with no conversion.
            'unrated' => ['cost_price' => '10.00', 'currency' => 'EUR', 'recorded_at' => '2026-11-05 09:00:00'],
            // Converted when received (#424): already in HUF.
            'received' => ['cost_price' => '4235.00', 'currency' => 'HUF', 'source_cost_price' => '11.00', 'source_currency' => 'EUR', 'exchange_rate' => '385', 'recorded_at' => '2026-11-11 09:00:00', 'source' => SupplierPriceHistory::SOURCE_PURCHASE_ORDER],
            // Older rows without a currency.
            'legacyLink' => ['cost_price' => '9.00', 'currency' => null, 'recorded_at' => '2026-10-01 09:00:00'],
            'legacyReceipt' => ['cost_price' => '9.50', 'currency' => null, 'recorded_at' => '2026-10-02 09:00:00', 'source' => SupplierPriceHistory::SOURCE_PURCHASE_ORDER, 'purchase_order_id' => $legacyPo->id],
        ];

        $ids = [];
        foreach ($rows as $key => $row) {
            $ids[$key] = SupplierPriceHistory::create($row + [
                'organization_id' => $this->org->id,
                'product_id' => $this->product->id,
                'supplier_id' => $this->eurSupplier->id,
                'source' => SupplierPriceHistory::SOURCE_SUPPLIER_LINK,
            ])->id;
        }

        $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($ids) {
                $history = collect($page->toArray()['props']['priceHistory'])->keyBy('id');

                $rated = $history[$ids['rated']];
                $this->assertSame('EUR', $rated['currency']);
                $this->assertTrue($rated['currency_known']);
                $this->assertSame('ok', $rated['converted']['status']);
                $this->assertSame('4235.00', $rated['converted']['amount']);
                $this->assertSame('2026-11-10', $rated['converted']['effective_date']);
                $this->assertSame('2026-11-10', $rated['converted']['requested_date']);

                $unrated = $history[$ids['unrated']];
                $this->assertSame('EUR', $unrated['currency']);
                $this->assertSame('unavailable', $unrated['converted']['status']);
                $this->assertNull($unrated['converted']['amount']);

                $received = $history[$ids['received']];
                $this->assertSame('HUF', $received['currency']);
                $this->assertNull($received['converted']);
                $this->assertSame('11.00', $received['source_cost_price']);
                $this->assertSame('EUR', $received['source_currency']);

                $legacyLink = $history[$ids['legacyLink']];
                $this->assertNull($legacyLink['currency']);
                $this->assertFalse($legacyLink['currency_known']);
                $this->assertNull($legacyLink['converted']);

                // A receipt was logged at the order's unit cost: the order's currency.
                $legacyReceipt = $history[$ids['legacyReceipt']];
                $this->assertSame('EUR', $legacyReceipt['currency']);
                $this->assertSame('purchase_order', $legacyReceipt['currency_source']);
                $this->assertSame('unavailable', $legacyReceipt['converted']['status']);

                return $page;
            });
    }

    public function test_a_supplier_link_edit_is_logged_in_the_links_currency(): void
    {
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50']]);

        $entry = SupplierPriceHistory::where('product_id', $this->product->id)->sole();

        $this->assertSame('12.50', $entry->cost_price);
        $this->assertSame('EUR', $entry->currency);
        $this->assertNull($entry->source_currency);
    }

    // ---------------------------------------------------------------- purchase orders

    public function test_the_po_form_carries_the_link_and_product_currencies_for_prefilling(): void
    {
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50', 'is_primary' => true]]);

        $this->actingAs($this->admin)
            ->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.0.currency', 'HUF')
                ->where('products.0.supplier_costs.0.cost_price', '12.50')
                ->where('products.0.supplier_costs.0.cost_currency', 'EUR')
            );
    }

    public function test_quick_reorder_prices_lines_only_from_amounts_in_the_po_currency(): void
    {
        $this->fakeFrankfurter(364.35);
        $hufSupplier = $this->supplier($this->org, 'HUF');
        $noLinkCost = $this->product($this->org, 'HUF');

        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50', 'is_primary' => true]]);
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'is_primary' => true]], $noLinkCost);

        $eurPo = app(ReorderService::class)->createDraftPurchaseOrder(
            $this->org->id, $this->eurSupplier->id,
            [$this->product->fresh('suppliers'), $noLinkCost->fresh('suppliers')],
            $this->admin->id, 'Reorder', 'quick_reorder', 'Quick reorder',
        );

        $this->assertSame('EUR', $eurPo->currency);
        $costs = $eurPo->items()->orderBy('id')->pluck('unit_cost')->all();
        // The EUR link cost; no EUR amount for the second, so not its HUF purchase price.
        $this->assertSame(['12.50', '0.00'], $costs);

        $this->linkSuppliers([['supplier_id' => $hufSupplier->id, 'is_primary' => true]], $noLinkCost);
        $hufPo = app(ReorderService::class)->createDraftPurchaseOrder(
            $this->org->id, $hufSupplier->id, [$noLinkCost->fresh('suppliers')],
            $this->admin->id, 'Reorder', 'quick_reorder', 'Quick reorder',
        );

        $this->assertSame('HUF', $hufPo->currency);
        $this->assertSame('3000.00', $hufPo->items()->sole()->unit_cost);
    }

    public function test_reorder_unit_cost_without_a_currency_is_unchanged(): void
    {
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50', 'is_primary' => true]]);
        $product = $this->product->fresh('suppliers');

        $this->assertSame('12.50', app(ReorderService::class)->unitCost($product, $product->suppliers->first()));
        $this->assertSame('3000.00', app(ReorderService::class)->unitCost($product));
    }

    // ---------------------------------------------------------------- API surfaces

    public function test_rest_returns_and_accepts_the_cost_currency(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $usdSupplier = $this->supplier($this->org, 'USD');

        $this->putJson("/api/v1/products/{$this->product->id}", [
            'suppliers' => [
                ['supplier_id' => $this->eurSupplier->id, 'cost_price' => 12.5, 'is_primary' => true],
                ['supplier_id' => $usdSupplier->id, 'cost_price' => 15, 'cost_currency' => 'EUR'],
            ],
        ])->assertOk();

        $suppliers = collect($this->getJson("/api/v1/products/{$this->product->id}")->assertOk()->json('data.suppliers'))->keyBy('id');

        $this->assertSame('EUR', $suppliers[$this->eurSupplier->id]['cost_currency']);
        $this->assertEquals(12.5, $suppliers[$this->eurSupplier->id]['cost_price']);
        $this->assertSame('EUR', $suppliers[$usdSupplier->id]['cost_currency']);
    }

    public function test_graphql_exposes_the_cost_currency_next_to_the_supplier_currency(): void
    {
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50', 'cost_currency' => 'USD', 'is_primary' => true]]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/graphql', ['query' => sprintf('{ product(id: %d) { suppliers { id currency cost_price cost_currency } } }', $this->product->id)])
            ->assertOk()
            ->assertJsonPath('data.product.suppliers.0.currency', 'EUR')
            ->assertJsonPath('data.product.suppliers.0.cost_price', 12.5)
            ->assertJsonPath('data.product.suppliers.0.cost_currency', 'USD');
    }

    public function test_graphql_mutations_accept_the_cost_currency(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/graphql', ['query' => sprintf(
            'mutation { updateProduct(id: %d, suppliers: [{supplier_id: %d, cost_price: 7, cost_currency: "GBP"}]) { id } }',
            $this->product->id, $this->eurSupplier->id,
        )])->assertOk()->assertJsonMissingPath('errors');

        $this->assertSame('GBP', $this->link()->cost_currency);
    }

    public function test_mcp_get_product_returns_the_cost_currency(): void
    {
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50', 'is_primary' => true]]);

        InventorosServer::actingAs($this->admin)
            ->tool(GetProductTool::class, ['id' => $this->product->id])
            ->assertOk()
            ->assertSee('cost_currency')
            ->assertSee('EUR');
    }

    public function test_the_export_and_import_carry_the_supplier_cost_currency(): void
    {
        $this->eurSupplier->update(['code' => 'EUSUP']);
        $this->linkSuppliers([['supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50', 'is_primary' => true]]);

        $export = new ProductsExport($this->org->id);
        $row = array_combine($export->headings(), $export->map($export->query()->sole()));
        $this->assertSame('EUR', $row['Supplier Cost Currency']);
        $this->assertEquals('12.50', $row['Supplier Cost']);

        $import = new ProductsImport($this->org->id);
        $import->collection(new Collection([collect([
            'sku' => $this->product->sku, 'name' => $this->product->name, 'price' => 9000, 'stock' => 0,
            'supplier_code' => 'EUSUP', 'supplier_cost' => '13.00', 'supplier_cost_currency' => 'usd',
        ])]));
        $this->assertSame([], $import->getStats()['warnings']);
        $this->assertSame('USD', $this->link()->cost_currency);

        $import = new ProductsImport($this->org->id);
        $import->collection(new Collection([collect([
            'sku' => $this->product->sku, 'name' => $this->product->name, 'price' => 9000, 'stock' => 0,
            'supplier_code' => 'EUSUP', 'supplier_cost' => '14.00', 'supplier_cost_currency' => 'Euro',
        ])]));
        $this->assertNotSame([], $import->getStats()['warnings']);
        // A bad currency is ignored: the link keeps its own.
        $this->assertSame('USD', $this->link()->cost_currency);
        $this->assertEquals(14.0, (float) $this->link()->cost_price);
    }

    // ---------------------------------------------------------------- migration

    public function test_the_migration_backfills_existing_links_with_the_suppliers_currency(): void
    {
        $migration = require base_path('database/migrations/2026_11_17_000002_add_cost_currency_to_product_supplier.php');
        $noCurrency = $this->supplier($this->org, 'EUR');
        $other = $this->product($this->org, 'HUF');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('product_supplier', 'cost_currency'));

        DB::table('product_supplier')->insert([
            ['product_id' => $this->product->id, 'supplier_id' => $this->eurSupplier->id, 'cost_price' => '12.50', 'is_primary' => true],
            ['product_id' => $other->id, 'supplier_id' => $noCurrency->id, 'cost_price' => '2900', 'is_primary' => true],
        ]);
        DB::table('suppliers')->where('id', $noCurrency->id)->update(['currency' => '']);

        $migration->up();

        $this->assertSame('EUR', $this->link()->cost_currency);
        // A supplier with a blank currency: the organization's.
        $this->assertSame('HUF', $this->link($other, $noCurrency)->cost_currency);
    }
}
