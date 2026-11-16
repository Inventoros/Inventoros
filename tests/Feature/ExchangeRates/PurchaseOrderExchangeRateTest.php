<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Models\Auth\Organization;
use App\Models\ExchangeRates\ExchangeRate;
use App\Models\ExchangeRates\ExchangeRateSetting;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\SupplierPriceHistory;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use App\Services\Documents\DocumentPdfService;
use App\Services\ExchangeRates\ExchangeRateSynchronizer;
use App\Services\PurchaseOrderEmailService;
use App\Services\PurchaseOrderService;
use App\Services\ReorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

/**
 * Purchase orders in a supplier's currency: the rate snapshot, overrides and
 * their permission, locking on send, receiving at the converted cost, the
 * approval threshold in the organization's currency, and older orders.
 */
final class PurchaseOrderExchangeRateTest extends TestCase
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
        Http::preventStrayRequests();

        $this->org = $this->organization();
        $this->admin = $this->user($this->org);
        $this->eurSupplier = $this->supplier($this->org, 'EUR');
        $this->product = $this->product($this->org);
    }

    protected function tearDown(): void
    {
        $this->tearDownExchangeRates();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = [], string $unitCost = '10.00', int $quantity = 30): array
    {
        return $overrides + [
            'supplier_id' => $this->eurSupplier->id,
            'order_date' => '2026-11-16',
            'currency' => 'EUR',
            'tax' => '0',
            'shipping' => '0',
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]],
        ];
    }

    private function create(User $user, array $overrides = [], string $unitCost = '10.00', int $quantity = 30): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->create($this->org->id, $user, $this->payload($overrides, $unitCost, $quantity));
    }

    public function test_a_foreign_currency_po_takes_the_providers_rate_and_converted_totals(): void
    {
        $this->fakeFrankfurter(364.35);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload(['shipping' => '12.50']))
            ->assertSessionHasNoErrors();

        $po = PurchaseOrder::firstOrFail();

        $this->assertSame('364.3500000000', $po->exchange_rate);
        $this->assertSame('EUR', $po->exchange_rate_from);
        $this->assertSame('HUF', $po->exchange_rate_to);
        $this->assertSame('frankfurter', $po->exchange_rate_provider);
        $this->assertSame('2026-11-16', $po->exchange_rate_effective_date->toDateString());
        $this->assertSame(PurchaseOrder::RATE_SOURCE_PROVIDER, $po->exchange_rate_source);
        $this->assertNull($po->exchange_rate_set_by);
        $this->assertNull($po->exchange_rate_locked_at);
        // 300.00 EUR + 12.50 EUR shipping = 312.50 EUR at 364.35.
        $this->assertSame('109305.00', $po->converted_subtotal);
        $this->assertSame('4554.38', $po->converted_shipping);
        $this->assertSame('113859.38', $po->converted_total);
        $this->assertSame('113859.38', $po->organizationCurrencyTotal());
    }

    public function test_a_po_in_the_organization_currency_is_unchanged(): void
    {
        $hufSupplier = $this->supplier($this->org, 'HUF');

        $po = $this->create($this->admin, ['supplier_id' => $hufSupplier->id, 'currency' => 'HUF']);

        Http::assertNothingSent();
        $this->assertNull($po->exchange_rate);
        $this->assertNull($po->converted_total);
        $this->assertFalse($po->isForeignCurrency());
        $this->assertSame('300.00', $po->organizationCurrencyTotal());
        $this->assertSame('same_currency', $po->exchangeRateSummary()['status']);
    }

    public function test_no_available_rate_never_blocks_saving_a_draft(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $po = PurchaseOrder::firstOrFail();
        $this->assertNull($po->exchange_rate);
        $this->assertNull($po->organizationCurrencyTotal());
        $this->assertSame('not_recorded', $po->exchangeRateSummary()['status']);
    }

    public function test_a_stale_rate_is_never_applied_on_its_own(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        ExchangeRate::create(['provider' => 'frankfurter', 'base_currency' => 'EUR', 'quote_currency' => 'HUF', 'rate' => '361.5', 'effective_date' => '2026-11-02', 'retrieved_at' => now()->subDays(14)]);

        $po = $this->create($this->admin);

        $this->assertNull($po->exchange_rate, 'A stale rate must not be applied silently.');
    }

    public function test_a_permitted_user_can_confirm_a_stale_rate(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        ExchangeRate::create(['provider' => 'frankfurter', 'base_currency' => 'EUR', 'quote_currency' => 'HUF', 'rate' => '361.5', 'effective_date' => '2026-11-02', 'retrieved_at' => now()->subDays(14)]);

        $po = $this->create($this->admin, ['exchange_rate_mode' => 'confirm']);

        $this->assertSame('361.5000000000', $po->exchange_rate);
        $this->assertSame(PurchaseOrder::RATE_SOURCE_CONFIRMED, $po->exchange_rate_source);
        $this->assertSame('frankfurter', $po->exchange_rate_provider);
        $this->assertSame('2026-11-02', $po->exchange_rate_effective_date->toDateString());
        $this->assertSame($this->admin->id, $po->exchange_rate_set_by);
    }

    public function test_a_manual_rate_needs_the_override_permission(): void
    {
        $this->fakeFrankfurter();
        $buyer = $this->user($this->org, 'member', ['view_purchase_orders', 'create_purchase_orders', 'edit_purchase_orders']);

        $this->actingAs($buyer)
            ->post(route('purchase-orders.store'), $this->payload(['exchange_rate_mode' => 'manual', 'exchange_rate' => '400']))
            ->assertSessionHasErrors('exchange_rate');
        $this->assertSame(0, PurchaseOrder::count());

        $this->actingAs($buyer)
            ->post(route('purchase-orders.store'), $this->payload(['exchange_rate_mode' => 'confirm']))
            ->assertSessionHasErrors('exchange_rate');

        $agent = $this->user($this->org, 'member', ['view_purchase_orders', 'create_purchase_orders', 'override_purchase_order_exchange_rate']);
        $this->actingAs($agent)
            ->post(route('purchase-orders.store'), $this->payload(['exchange_rate_mode' => 'manual', 'exchange_rate' => '398.125']))
            ->assertSessionHasNoErrors();

        $po = PurchaseOrder::firstOrFail();
        $this->assertSame('398.1250000000', $po->exchange_rate);
        $this->assertSame(PurchaseOrder::RATE_SOURCE_MANUAL, $po->exchange_rate_source);
        $this->assertNull($po->exchange_rate_provider);
        $this->assertSame($agent->id, $po->exchange_rate_set_by);
        $this->assertSame('119437.50', $po->converted_total);
    }

    public function test_manual_rates_are_validated(): void
    {
        foreach (['0', '-5', 'abc', '1.12345678901', '12345678901'] as $bad) {
            $this->actingAs($this->admin)
                ->post(route('purchase-orders.store'), $this->payload(['exchange_rate_mode' => 'manual', 'exchange_rate' => $bad]))
                ->assertSessionHasErrors('exchange_rate');
        }

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload(['exchange_rate_mode' => 'manual']))
            ->assertSessionHasErrors('exchange_rate');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_the_rate_follows_the_draft_and_locks_when_the_po_is_sent(): void
    {
        $this->fakeFrankfurter(364.35);
        $po = $this->create($this->admin);

        // A later rate does not move a draft whose currency and date stay.
        ExchangeRate::query()->update(['rate' => '370']);
        $po = app(PurchaseOrderService::class)->update($po, $this->org->id, ['notes' => 'Call first'], $this->admin);
        $this->assertSame('364.3500000000', $po->exchange_rate);

        // Asking for the provider rate again picks it up while still a draft.
        $po = app(PurchaseOrderService::class)->update($po, $this->org->id, ['exchange_rate_mode' => 'auto'], $this->admin);
        $this->assertSame('370.0000000000', $po->exchange_rate);
        $this->assertSame('111000.00', $po->converted_total);

        app(PurchaseOrderEmailService::class)->send($po, $this->admin);
        $po->refresh();

        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->status);
        $this->assertNotNull($po->exchange_rate_locked_at);
        $this->assertTrue($po->exchangeRateSummary()['locked']);
    }

    public function test_later_rate_updates_never_change_a_sent_po(): void
    {
        Http::fake(['api.frankfurter.dev/*' => Http::sequence()
            ->push(['base' => 'EUR', 'date' => '2026-11-16', 'rates' => ['HUF' => 364.35]])
            ->push(['base' => 'EUR', 'date' => '2026-11-17', 'rates' => ['HUF' => 380.0]])]);
        $po = $this->create($this->admin);
        app(PurchaseOrderEmailService::class)->send($po, $this->admin);

        // A new day, a new rate, an automatic sync.
        $this->atTime('2026-11-17 18:00:00');
        ExchangeRateSetting::forOrganization($this->org->id)->update(['sync_enabled' => true]);
        app(ExchangeRateSynchronizer::class)->run([ExchangeRateSetting::forOrganization($this->org->id)]);
        $this->assertSame(2, ExchangeRate::count(), 'History is kept: the new day is a new row.');

        $po->refresh();
        $this->assertSame('364.3500000000', $po->exchange_rate);
        $this->assertSame('109305.00', $po->converted_total);

        // Nothing can rewrite a locked snapshot, even directly.
        $this->expectException(LogicException::class);
        $po->forceFill(['exchange_rate' => '380'])->save();
    }

    public function test_changing_the_rate_of_an_approved_draft_sends_it_back_for_approval(): void
    {
        $this->fakeFrankfurter(364.35);
        $po = $this->create($this->admin);
        $po->forceFill(['approval_status' => PurchaseOrder::APPROVAL_APPROVED, 'approved_by' => $this->admin->id, 'approved_at' => now()])->save();

        $po = app(PurchaseOrderService::class)->update($po, $this->org->id, ['exchange_rate_mode' => 'manual', 'exchange_rate' => '390'], $this->admin);

        $this->assertNull($po->approval_status);
    }

    public function test_the_approval_threshold_is_judged_in_the_organization_currency(): void
    {
        $this->fakeFrankfurter(364.35);
        $this->org->update(['settings' => ['approvals' => ['purchase_orders_enabled' => true, 'purchase_orders_threshold' => 100000]]]);

        // 300 EUR = 109,305 HUF: over the 100,000 HUF threshold, although 300 < 100,000.
        $large = $this->create($this->admin, [], '10.00', 30);
        $this->assertTrue($large->fresh()->needsApproval());

        // 200 EUR = 72,870 HUF: under it.
        $small = $this->create($this->admin, [], '10.00', 20);
        $this->assertFalse($small->fresh()->needsApproval());
    }

    public function test_a_foreign_po_without_a_rate_needs_approval_when_a_threshold_is_set(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $this->org->update(['settings' => ['approvals' => ['purchase_orders_enabled' => true, 'purchase_orders_threshold' => 100000]]]);

        $po = $this->create($this->admin, [], '1.00', 1);

        $this->assertNull($po->exchange_rate);
        $this->assertTrue($po->fresh()->needsApproval());
    }

    public function test_receiving_books_the_cost_in_the_organization_currency_at_the_locked_rate(): void
    {
        $this->fakeFrankfurter(364.35);
        $po = $this->create($this->admin, [], '10.25', 4);
        app(PurchaseOrderEmailService::class)->send($po, $this->admin);

        // The rate moves after the PO was sent; receiving still uses the PO's.
        ExchangeRate::query()->update(['rate' => '399']);

        $line = $po->items()->firstOrFail();
        $this->actingAs($this->admin);
        app(PurchaseOrderService::class)->receive($po->fresh(), $this->admin, [['id' => $line->id, 'quantity_to_receive' => 4]]);

        $entry = SupplierPriceHistory::withoutGlobalScopes()->where('purchase_order_id', $po->id)->firstOrFail();
        // 10.25 EUR * 364.35 = 3734.5875 -> 3734.59 HUF.
        $this->assertSame('3734.59', $entry->cost_price);
        $this->assertSame('HUF', $entry->currency);
        $this->assertSame('10.25', $entry->source_cost_price);
        $this->assertSame('EUR', $entry->source_currency);
        $this->assertSame('364.3500000000', $entry->exchange_rate);
        $this->assertSame('3734.59', $line->fresh()->organizationCurrencyUnitCost());
        $this->assertSame(4, (int) $this->product->fresh()->stock);
    }

    public function test_an_older_foreign_po_without_a_rate_keeps_working_and_is_labelled(): void
    {
        $po = $this->legacyPurchaseOrder($this->org, $this->eurSupplier, $this->product, 'EUR');

        $this->actingAs($this->admin)
            ->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('exchangeRate.status', 'not_recorded')
                ->where('exchangeRate.rate', null)
                ->where('exchangeRate.converted.total', null)
                ->where('canRecordExchangeRate', true));

        $line = $po->items()->firstOrFail();
        $this->actingAs($this->admin);
        app(PurchaseOrderService::class)->receive($po, $this->admin, [['id' => $line->id, 'quantity_to_receive' => 2]]);

        $entry = SupplierPriceHistory::withoutGlobalScopes()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame('10.00', $entry->cost_price);
        $this->assertSame('EUR', $entry->currency, 'Without a rate the cost keeps its own currency label, never the organization\'s.');
        $this->assertNull($entry->exchange_rate);
        Http::assertNothingSent();
    }

    public function test_an_older_po_in_the_organization_currency_receives_exactly_as_before(): void
    {
        $hufSupplier = $this->supplier($this->org, 'HUF');
        $po = $this->legacyPurchaseOrder($this->org, $hufSupplier, $this->product, 'HUF', PurchaseOrder::STATUS_SENT, '2500.00', 2);

        $line = $po->items()->firstOrFail();
        $this->actingAs($this->admin);
        app(PurchaseOrderService::class)->receive($po, $this->admin, [['id' => $line->id, 'quantity_to_receive' => 2]]);

        $entry = SupplierPriceHistory::withoutGlobalScopes()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame('2500.00', $entry->cost_price);
        $this->assertNull($entry->source_cost_price);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $po->fresh()->status);
    }

    public function test_a_rate_can_be_recorded_once_on_a_sent_po_that_has_none(): void
    {
        $po = $this->legacyPurchaseOrder($this->org, $this->eurSupplier, $this->product, 'EUR');

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.exchange-rate.record', $po), ['exchange_rate' => '363.2'])
            ->assertRedirect(route('purchase-orders.show', $po));

        $po->refresh();
        $this->assertSame('363.2000000000', $po->exchange_rate);
        $this->assertSame(PurchaseOrder::RATE_SOURCE_MANUAL, $po->exchange_rate_source);
        $this->assertNotNull($po->exchange_rate_locked_at);
        $this->assertSame('18160.00', $po->converted_total);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.exchange-rate.record', $po), ['exchange_rate' => '999'])
            ->assertSessionHasErrors('exchange_rate');
        $this->assertSame('363.2000000000', $po->fresh()->exchange_rate);
    }

    public function test_recording_a_rate_needs_the_override_permission_and_the_same_organization(): void
    {
        $po = $this->legacyPurchaseOrder($this->org, $this->eurSupplier, $this->product, 'EUR');
        $editor = $this->user($this->org, 'member', ['view_purchase_orders', 'edit_purchase_orders']);

        $this->actingAs($editor)
            ->post(route('purchase-orders.exchange-rate.record', $po), ['exchange_rate' => '363.2'])
            ->assertForbidden();

        $other = $this->organization('Other Org');
        $outsider = $this->user($other);
        $this->actingAs($outsider)
            ->post(route('purchase-orders.exchange-rate.record', $po), ['exchange_rate' => '363.2'])
            ->assertStatus(404);

        $this->assertNull($po->fresh()->exchange_rate);
    }

    public function test_the_form_quote_endpoint_returns_the_rate_for_the_users_organization(): void
    {
        $this->fakeFrankfurter(364.35);

        $this->actingAs($this->admin)
            ->getJson(route('purchase-orders.exchange-rate', ['currency' => 'eur', 'date' => '2026-11-16']))
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.rate', '364.35')
            ->assertJsonPath('data.from', 'EUR')
            ->assertJsonPath('data.to', 'HUF')
            ->assertJsonPath('data.provider', 'frankfurter')
            ->assertJsonPath('data.effective_date', '2026-11-16');

        $viewer = $this->user($this->org, 'member', ['view_purchase_orders']);
        $this->actingAs($viewer)->getJson(route('purchase-orders.exchange-rate', ['currency' => 'EUR']))->assertForbidden();
        $this->actingAs($this->admin)->getJson(route('purchase-orders.exchange-rate', ['currency' => 'EURO']))->assertStatus(422);
    }

    public function test_create_and_edit_pages_carry_the_exchange_rate_props(): void
    {
        $this->actingAs($this->admin)
            ->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('exchangeRates.canOverride', true)
                ->where('exchangeRates.provider', 'frankfurter')
                ->where('exchangeRates.providerAvailable', true));

        $this->fakeFrankfurter();
        $po = $this->create($this->admin);
        $buyer = $this->user($this->org, 'member', ['view_purchase_orders', 'edit_purchase_orders']);

        $this->actingAs($buyer)
            ->get(route('purchase-orders.edit', $po))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('exchangeRates.canOverride', false)
                ->where('exchangeRate.rate', '364.35')
                ->where('exchangeRate.source', 'provider'));
    }

    public function test_the_receive_page_says_which_rate_costs_are_booked_at(): void
    {
        $this->fakeFrankfurter();
        $po = $this->create($this->admin);
        app(PurchaseOrderEmailService::class)->send($po, $this->admin);

        $this->actingAs($this->admin)
            ->get(route('purchase-orders.receive', $po))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('exchangeRate.rate', '364.35')->where('exchangeRate.locked', true));
    }

    public function test_the_pdf_shows_both_currencies(): void
    {
        $this->fakeFrankfurter(364.35);
        $po = $this->create($this->admin);
        $po->load(['items', 'organization', 'supplier']);

        $html = view('pdf.purchase-order-invoice', [
            'purchaseOrder' => $po,
            'organization' => $po->organization,
            'supplier' => $po->supplier,
            'generatedDate' => 'November 16, 2026',
            'exchangeRateSource' => DocumentPdfService::exchangeRateSource($po),
        ])->render();

        $this->assertStringContainsString('EUR 300.00', $html);
        $this->assertStringContainsString('1 EUR = 364.35 HUF', $html);
        $this->assertStringContainsString('Frankfurter (European Central Bank), November 16, 2026', $html);
        $this->assertStringContainsString('HUF 109,305.00', $html);

        // And the PDF itself renders.
        $this->actingAs($this->admin)->get(route('purchase-orders.invoice.download', $po))->assertOk();
    }

    public function test_the_pdf_of_an_older_foreign_po_says_no_rate_was_recorded(): void
    {
        $po = $this->legacyPurchaseOrder($this->org, $this->eurSupplier, $this->product, 'EUR');
        $po->load(['items', 'organization', 'supplier']);

        $html = view('pdf.purchase-order-invoice', [
            'purchaseOrder' => $po, 'organization' => $po->organization, 'supplier' => $po->supplier,
            'generatedDate' => 'today', 'exchangeRateSource' => '',
        ])->render();

        $this->assertStringContainsString('No exchange rate to HUF recorded', $html);
    }

    public function test_quick_reorder_drafts_take_the_rate_too(): void
    {
        $this->fakeFrankfurter(364.35);
        $this->product->suppliers()->attach($this->eurSupplier->id, ['cost_price' => 4, 'is_primary' => true]);

        $po = app(ReorderService::class)->createDraftPurchaseOrder(
            $this->org->id, $this->eurSupplier->id, [$this->product->fresh('suppliers')], $this->admin->id, 'Reorder', 'quick_reorder', 'Quick reorder',
        );

        $this->assertSame('EUR', $po->currency);
        $this->assertSame('364.3500000000', $po->fresh()->exchange_rate);
    }
}
