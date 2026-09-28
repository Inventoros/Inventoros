<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAdjustmentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Warehouses\WarehouseAccessFixture;
use Tests\TestCase;

/**
 * POST products/{product}/variants/{variant}/adjust-stock (REST, and the
 * web-session mount the VariantStockAdjuster uses) used to call
 * StockAdjustment::adjustVariant directly, so it skipped the organization's
 * approval rules and warehouse access: with approvals on, a clerk could add
 * 500 units to a variant with no request held. It now goes through the same
 * ApprovalService::submitStockAdjustment the stock adjustment surfaces use.
 */
final class VariantAdjustStockEndpointTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpWarehouseAccess();

        $this->productA->update(['has_variants' => true]);
        $this->productB->update(['has_variants' => true]);
        $this->variantA = $this->variant($this->productA, 'ALPHA-1-S');
        $this->variantB = $this->variant($this->productB, 'BRAVO-1-S');
    }

    private function variant($product, string $sku): ProductVariant
    {
        return ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $product->id,
            'sku' => $sku, 'title' => 'S', 'option_values' => ['Size' => 'S'],
            'price' => 10, 'purchase_price' => 5, 'stock' => 10, 'is_active' => true,
        ]);
    }

    private function enableApprovals(): void
    {
        $this->organization->update(['settings' => ['approvals' => ['stock_adjustments_enabled' => true]]]);
    }

    private function restApiAdjust(User $user, ProductVariant $variant, int $quantity): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson("/api/v1/products/{$variant->product_id}/variants/{$variant->id}/adjust-stock", [
            'quantity' => $quantity, 'type' => 'increase', 'reason' => 'Found a box',
        ]);
    }

    public function test_rest_variant_adjustment_is_held_when_approvals_are_on(): void
    {
        $this->enableApprovals();

        $this->restApiAdjust($this->admin, $this->variantA, 500)
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending_approval');

        $this->assertSame(10, (int) $this->variantA->fresh()->stock);
        $request = StockAdjustmentRequest::sole();
        $this->assertSame($this->variantA->id, (int) $request->product_variant_id);
        $this->assertSame(500, (int) $request->quantity);
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_web_mounted_variant_adjustment_is_held_when_approvals_are_on(): void
    {
        $this->enableApprovals();

        $this->actingAs($this->admin)
            ->postJson(route('products.variants.adjust-stock', [$this->productA, $this->variantA]), [
                'quantity' => 500, 'type' => 'increase',
            ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending_approval');

        $this->assertSame(10, (int) $this->variantA->fresh()->stock);
        $this->assertSame(1, StockAdjustmentRequest::count());
    }

    public function test_variant_adjustment_applies_through_the_ledger_when_approvals_are_off(): void
    {
        $this->restApiAdjust($this->admin, $this->variantA, 5)
            ->assertOk()
            ->assertJsonPath('data.stock', 15);

        $this->assertSame(15, (int) $this->variantA->fresh()->stock);
        $adjustment = StockAdjustment::sole();
        $this->assertSame($this->variantA->id, (int) $adjustment->product_variant_id);
        $this->assertSame($this->admin->id, (int) $adjustment->user_id);
    }

    public function test_a_restricted_user_cannot_adjust_a_variant_stocked_in_another_warehouse(): void
    {
        $this->restApiAdjust($this->restricted, $this->variantB, 5)->assertForbidden();

        $this->assertSame(10, (int) $this->variantB->fresh()->stock);
        $this->assertSame(0, StockAdjustment::count());
        $this->assertSame(0, StockAdjustmentRequest::count());
    }

    public function test_a_restricted_user_can_adjust_a_variant_in_their_own_warehouse(): void
    {
        $this->restApiAdjust($this->restricted, $this->variantA, 5)->assertOk();

        $this->assertSame(15, (int) $this->variantA->fresh()->stock);
    }
}
