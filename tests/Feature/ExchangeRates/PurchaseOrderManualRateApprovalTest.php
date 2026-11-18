<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The purchase order approval threshold is set in the organization's
 * currency. A rate typed in by the person raising the PO must not decide
 * whether the PO stays under it.
 */
final class PurchaseOrderManualRateApprovalTest extends TestCase
{
    use ExchangeRateFixtures, RefreshDatabase;

    private Organization $org;

    private Supplier $eurSupplier;

    private Product $product;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->atTime();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();

        $this->org = $this->organization();
        $this->org->update(['settings' => ['approvals' => ['purchase_orders_enabled' => true, 'purchase_orders_threshold' => 100000]]]);
        $this->eurSupplier = $this->supplier($this->org, 'EUR');
        $this->product = $this->product($this->org);

        // The Purchasing Agent permissions: may override rates, may not approve.
        $this->agent = $this->user($this->org, 'member', [
            'view_purchase_orders', 'create_purchase_orders', 'edit_purchase_orders', 'override_purchase_order_exchange_rate',
        ]);
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
    private function payload(array $overrides = []): array
    {
        // 3,000 EUR: about 1.1 million HUF at the provider's rate.
        return $overrides + [
            'supplier_id' => $this->eurSupplier->id,
            'order_date' => '2026-11-16',
            'currency' => 'EUR',
            'tax' => '0',
            'shipping' => '0',
            'items' => [['product_id' => $this->product->id, 'quantity' => 300, 'unit_cost' => '10.00']],
        ];
    }

    public function test_a_hand_entered_rate_cannot_take_a_po_under_the_approval_threshold(): void
    {
        $this->fakeFrankfurter(364.35);

        $this->actingAs($this->agent)
            ->post(route('purchase-orders.store'), $this->payload(['exchange_rate_mode' => 'manual', 'exchange_rate' => '0.0001']))
            ->assertSessionHasNoErrors();

        $po = PurchaseOrder::firstOrFail();
        $this->assertSame('0.30', $po->converted_total);
        $this->assertTrue($po->needsApproval());

        $this->actingAs($this->agent)
            ->post(route('purchase-orders.mark-sent', $po))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
    }

    public function test_a_hand_entered_rate_on_an_existing_draft_also_needs_approval(): void
    {
        $this->fakeFrankfurter(364.35);

        // Under the threshold at the provider's rate: 200 EUR = 72,870 HUF.
        $this->actingAs($this->agent)
            ->post(route('purchase-orders.store'), $this->payload(['items' => [['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => '10.00']]]))
            ->assertSessionHasNoErrors();
        $po = PurchaseOrder::firstOrFail();
        $this->assertFalse($po->needsApproval());

        $this->actingAs($this->agent)
            ->put(route('purchase-orders.update', $po), $this->payload([
                'exchange_rate_mode' => 'manual',
                'exchange_rate' => '0.0001',
                'items' => [['id' => $po->items()->value('id'), 'product_id' => $this->product->id, 'quantity' => 300, 'unit_cost' => '10.00']],
            ]))
            ->assertSessionHasNoErrors();

        $po->refresh();
        $this->assertSame(PurchaseOrder::RATE_SOURCE_MANUAL, $po->exchange_rate_source);
        $this->assertTrue($po->needsApproval());
    }

    public function test_a_provider_rate_is_still_judged_against_the_threshold(): void
    {
        $this->fakeFrankfurter(364.35);

        $this->actingAs($this->agent)
            ->post(route('purchase-orders.store'), $this->payload(['items' => [['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => '10.00']]]))
            ->assertSessionHasNoErrors();

        $this->assertFalse(PurchaseOrder::firstOrFail()->needsApproval());
    }

    public function test_without_a_threshold_setting_a_manual_rate_changes_nothing(): void
    {
        $this->fakeFrankfurter(364.35);
        $this->org->update(['settings' => ['approvals' => ['purchase_orders_enabled' => false]]]);

        $this->actingAs($this->agent)
            ->post(route('purchase-orders.store'), $this->payload(['exchange_rate_mode' => 'manual', 'exchange_rate' => '0.0001']))
            ->assertSessionHasNoErrors();

        $this->assertFalse(PurchaseOrder::firstOrFail()->needsApproval());
    }
}
