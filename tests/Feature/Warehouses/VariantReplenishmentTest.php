<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\VariantLocationStock;
use App\Models\Inventory\VariantReplenishmentPolicy;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\PurchaseOrderService;
use App\Services\VariantReplenishmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class VariantReplenishmentTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    private ProductVariant $variant;

    private Supplier $supplier;

    private VariantReplenishmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        $this->setUpWarehouseAccess();
        $this->actingAs($this->admin);
        $this->productA->update(['has_variants' => true]);
        $this->variant = ProductVariant::create(['organization_id' => $this->organization->id,
            'product_id' => $this->productA->id, 'sku' => 'ALPHA-RED', 'title' => 'Red', 'option_values' => [], 'stock' => 42, 'is_active' => true]);
        foreach ([[$this->locationA, 40], [$this->locationB, 2]] as [$location, $quantity]) {
            VariantLocationStock::updateOrCreate(['organization_id' => $this->organization->id,
                'product_variant_id' => $this->variant->id, 'location_id' => $location->id], ['quantity' => $quantity]);
        }
        $this->supplier = Supplier::create(['organization_id' => $this->organization->id,
            'name' => 'Variant supplier', 'currency' => 'USD', 'is_active' => true]);
        $this->service = app(VariantReplenishmentService::class);
    }

    private function data(array $overrides = []): array
    {
        return $overrides + ['product_variant_id' => $this->variant->id, 'warehouse_id' => $this->warehouseB->id,
            'location_id' => $this->locationB->id, 'supplier_id' => $this->supplier->id, 'reorder_point' => 5,
            'target_stock' => 10, 'minimum_order_quantity' => 1, 'unit_cost' => '2.50', 'currency' => 'USD'];
    }

    private function policy(array $overrides = []): VariantReplenishmentPolicy
    {
        return $this->service->save($this->admin, $this->data($overrides));
    }

    public function test_draft_buys_exact_variant_for_warehouse_and_counts_existing_inbound(): void
    {
        $policy = $this->policy();
        $this->assertFalse($policy->auto_reorder);
        $forecast = $this->service->forecasts(collect([$policy]))[$policy->id];
        $this->assertSame(['on_hand' => 2, 'incoming' => 0, 'position' => 2, 'suggested_quantity' => 8], $forecast);
        $po = $this->service->createDraft($this->admin, $policy);
        $line = $po->items()->sole();
        $this->assertSame('draft', $po->status);
        $this->assertSame('USD', $po->currency);
        $this->assertEquals('20.00', $po->total);
        $this->assertEquals('2.50', $line->unit_cost);
        $this->assertSame($this->variant->id, $line->product_variant_id);
        $this->assertSame($this->locationB->id, $line->receiving_location_id);
        $this->assertSame($policy->id, $line->replenishment_policy_id);
        $this->assertSame(8, $line->quantity_ordered);
        $this->assertNull($this->service->createDraft($this->admin, $policy));
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('stock_adjustments', 0);
        $forecast = $this->service->forecasts(collect([$policy]))[$policy->id];
        $this->assertSame(8, $forecast['incoming']);
        $this->assertSame(0, $forecast['suggested_quantity']);
    }

    public function test_receipt_goes_to_configured_variant_bin_and_restricted_clerk_cannot_receive_it(): void
    {
        $po = $this->service->createDraft($this->admin, $this->policy());
        $line = $po->items()->sole();
        $po->update(['status' => 'sent']);
        $this->actingAs($this->restricted)->post(route('purchase-orders.receive', $po),
            ['items' => [['id' => $line->id, 'quantity_to_receive' => 3]]])->assertForbidden();
        $this->actingAs($this->admin);
        $received = app(PurchaseOrderService::class)->receive($po, $this->admin, [['id' => $line->id, 'quantity_to_receive' => 3]]);
        $this->assertSame(1, $received);
        $this->assertDatabaseHas('variant_location_stocks', ['product_variant_id' => $this->variant->id, 'location_id' => $this->locationB->id, 'quantity' => 5]);
        $this->assertDatabaseHas('variant_location_stocks', ['product_variant_id' => $this->variant->id, 'location_id' => $this->locationA->id, 'quantity' => 40]);
        $this->assertSame(45, $this->variant->fresh()->stock);
        $policy = VariantReplenishmentPolicy::sole();
        $this->assertSame(5, $this->service->forecasts(collect([$policy]))[$policy->id]['incoming']);
        $this->assertNull($this->service->createDraft($this->admin, $policy));
    }

    public function test_closed_rejected_and_deleted_orders_do_not_cover_demand(): void
    {
        $policy = $this->policy();
        foreach (['cancelled', 'rejected', 'deleted'] as $state) {
            $po = $this->service->createDraft($this->admin, $policy);
            $this->assertNotNull($po);
            match ($state) {
                'cancelled' => $po->update(['status' => 'cancelled']),
                'rejected' => $po->update(['approval_status' => 'rejected']),
                'deleted' => $po->delete(),
            };
        }
        $this->assertNotNull($this->service->createDraft($this->admin, $policy));
    }

    public function test_minimum_quantity_and_each_warehouse_are_independent(): void
    {
        $b = $this->policy(['minimum_order_quantity' => 12]);
        $a = $this->policy(['warehouse_id' => $this->warehouseA->id, 'location_id' => $this->locationA->id]);
        $this->assertNull($this->service->createDraft($this->admin, $a));
        $this->assertSame(12, $this->service->createDraft($this->admin, $b)->items()->sole()->quantity_ordered);
    }

    public function test_foreign_keys_locations_and_invalid_parent_types_are_rejected(): void
    {
        $this->postJson(route('variant-replenishment.store'), $this->data(['location_id' => $this->locationA->id]))->assertNotFound();
        $this->productA->update(['type' => 'kit']);
        $this->postJson(route('variant-replenishment.store'), $this->data())->assertNotFound();
        $this->assertDatabaseCount('variant_replenishment_policies', 0);
    }

    public function test_stale_currency_or_unavailable_destination_does_not_create_an_order(): void
    {
        $policy = $this->policy();
        $this->supplier->update(['currency' => 'EUR']);
        $this->postJson(route('variant-replenishment.draft', $policy))->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->supplier->update(['currency' => 'USD']);
        $this->locationB->update(['is_active' => false]);
        $this->postJson(route('variant-replenishment.draft', $policy))->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_deleted_policy_can_be_restored_without_breaking_order_evidence(): void
    {
        $policy = $this->policy();
        $po = $this->service->createDraft($this->admin, $policy);
        $this->service->remove($this->admin, $policy);
        $this->assertSoftDeleted($policy);
        $restored = $this->policy();
        $this->assertSame($policy->id, $restored->id);
        $this->assertFalse($restored->trashed());
        $this->assertSame($policy->id, $po->items()->sole()->replenishment_policy_id);
        $this->assertNull($this->service->createDraft($this->admin, $restored));
    }

    public function test_scheduler_is_opt_in_and_rechecks_responsible_user_permissions(): void
    {
        $policy = $this->policy();
        $this->artisan('inventory:replenish-variants')->assertSuccessful();
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->policy(['auto_reorder' => true]);
        $this->artisan('inventory:replenish-variants')->assertSuccessful();
        $this->artisan('inventory:replenish-variants')->assertSuccessful();
        $this->assertDatabaseCount('purchase_orders', 1);
        PurchaseOrder::sole()->update(['status' => 'cancelled']);
        $policy->update(['managed_by' => $this->restricted->id]);
        $this->artisan('inventory:replenish-variants')->assertSuccessful();
        $this->assertDatabaseCount('purchase_orders', 1);
    }

    public function test_read_options_and_writes_respect_warehouse_access_and_scoped_tokens(): void
    {
        $policy = $this->policy();
        $this->actingAs($this->restricted)->getJson('/api/v1/variant-replenishment')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('variant-replenishment.options', ['warehouse_id' => $this->warehouseB->id]))->assertForbidden();
        $this->postJson(route('variant-replenishment.store'), $this->data())->assertForbidden();
        $this->assertDatabaseCount('variant_replenishment_policies', 1);
    }

    public function test_read_only_personal_token_cannot_change_policy_or_create_draft(): void
    {
        $policy = $this->policy();
        $token = $this->admin->createToken('read', ['view_products'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/variant-replenishment')->assertOk();
        $this->withToken($token)->postJson('/api/v1/variant-replenishment', $this->data())->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/variant-replenishment/'.$policy->id.'/draft')->assertForbidden();
    }

    public function test_order_multiples_round_up_to_target_and_do_not_leave_another_draft_due(): void
    {
        $policy = $this->policy(['reorder_quantity' => 6]);
        $this->assertSame(12, $this->service->createDraft($this->admin, $policy)->items()->sole()->quantity_ordered);
        $this->assertNull($this->service->createDraft($this->admin, $policy));
    }

    public function test_manual_inbound_without_destination_uses_parent_location_warehouse(): void
    {
        $b = $this->policy();
        $po = $this->service->createDraft($this->admin, $b);
        $po->items()->sole()->update(['receiving_location_id' => null]);
        $a = $this->policy(['warehouse_id' => $this->warehouseA->id, 'location_id' => $this->locationA->id,
            'reorder_point' => 80, 'target_stock' => 100]);
        $forecasts = $this->service->forecasts(collect([$a, $b]));
        $this->assertSame(8, $forecasts[$a->id]['incoming']);
        $this->assertSame(0, $forecasts[$b->id]['incoming']);
        $this->assertSame(52, $this->service->createDraft($this->admin, $a)->items()->sole()->quantity_ordered);
        $this->assertSame(8, $this->service->createDraft($this->admin, $b)->items()->sole()->quantity_ordered);
    }

    public function test_po_edit_preserves_the_selected_receiving_destination_and_policy_link(): void
    {
        $policy = $this->policy();
        $po = $this->service->createDraft($this->admin, $policy);
        $line = $po->items()->sole();
        app(PurchaseOrderService::class)->update($po, $this->organization->id, ['items' => [[
            'id' => $line->id, 'product_id' => $this->productA->id, 'product_variant_id' => $this->variant->id,
            'quantity' => 9, 'unit_cost' => '3.00',
        ]]]);
        $this->assertSame($this->locationB->id, $line->fresh()->receiving_location_id);
        $this->assertSame($policy->id, $line->fresh()->replenishment_policy_id);
    }

    public function test_unavailable_destination_rolls_back_receiving_quantity_and_stock(): void
    {
        $po = $this->service->createDraft($this->admin, $this->policy());
        $line = $po->items()->sole();
        $po->update(['status' => 'sent']);
        $this->locationB->delete();
        $this->postJson(route('purchase-orders.receive', $po), ['items' => [['id' => $line->id, 'quantity_to_receive' => 3]]])->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, $line->fresh()->quantity_received);
        $this->assertSame(42, $this->variant->fresh()->stock);
    }

    public function test_large_money_total_is_rejected_without_partial_order(): void
    {
        $policy = $this->policy(['unit_cost' => '9999999999.99']);
        $this->postJson(route('variant-replenishment.draft', $policy))->assertUnprocessable()->assertJsonValidationErrors('unit_cost');
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('purchase_order_items', 0);
    }

    public function test_edit_scoped_token_cannot_enable_future_automatic_purchases(): void
    {
        $token = $this->admin->createToken('product editor', ['view_products', 'edit_products'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/variant-replenishment', $this->data(['auto_reorder' => true]))->assertForbidden();
        $this->assertDatabaseCount('variant_replenishment_policies', 0);
        $this->withToken($token)->postJson('/api/v1/variant-replenishment', $this->data())->assertCreated();
    }
}
