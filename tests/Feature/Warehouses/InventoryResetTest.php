<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Services\InventoryResetService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InventoryResetTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
    }

    private function preview(): array
    {
        $this->actingAs($this->admin)->get(route('products.stock-reset.preview'))->assertOk();

        return session('stock_reset_preview');
    }

    private function submit(array $preview, array $overrides = [])
    {
        return $this->post(route('products.stock-reset.store'), array_merge([
            'token' => $preview['token'], 'confirmation' => 'ZERO STOCK', 'password' => 'password', 'reason' => 'Restarting stock records after test import',
        ], $overrides));
    }

    public function test_reset_zeroes_all_bins_variants_and_tracked_stock_without_deleting_records_or_crossing_tenants(): void
    {
        $foreign = Product::factory()->create(['stock' => 17]);
        $this->productA->update(['has_variants' => true]);
        $variant = ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->productA->id,
            'sku' => 'RESET-RED', 'title' => 'Red', 'stock' => 8, 'is_active' => true, 'option_values' => ['Colour' => 'Red'],
        ]);
        $available = ProductSerial::create(['organization_id' => $this->organization->id, 'product_id' => $this->productB->id, 'serial_number' => 'RESET-1', 'status' => 'available']);
        $sold = ProductSerial::create(['organization_id' => $this->organization->id, 'product_id' => $this->productB->id, 'serial_number' => 'RESET-2', 'status' => 'sold']);
        $batch = ProductBatch::create(['organization_id' => $this->organization->id, 'product_id' => $this->productB->id, 'batch_number' => 'RESET-BATCH', 'quantity' => 9]);
        $this->submit($this->preview())->assertRedirect(route('products.index'))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->productA->fresh()->stock);
        $this->assertSame(0, $this->productB->fresh()->stock);
        $this->assertSame(0, $variant->fresh()->stock);
        $this->assertSame(0, (int) DB::table('product_location_stocks')->sum('quantity'));
        $this->assertSame(0, (int) DB::table('variant_location_stocks')->sum('quantity'));
        $this->assertSame('written_off', $available->fresh()->status);
        $this->assertSame('sold', $sold->fresh()->status);
        $this->assertSame(0, $batch->fresh()->quantity);
        $this->assertSame(17, $foreign->fresh()->stock);
        $this->assertDatabaseCount('products', 3);
        $this->assertDatabaseHas('activity_logs', ['action' => 'inventory_reset', 'subject_id' => $batch->id, 'subject_type' => $batch->getMorphClass(), 'user_id' => $this->admin->id]);
        $this->assertSame(0, StockAdjustment::where('user_id', '<>', $this->admin->id)->count());
    }

    public function test_non_admins_cannot_preview_or_submit_even_with_stock_permissions(): void
    {
        $this->actingAs($this->restricted)->get(route('products.stock-reset.preview'))->assertForbidden();
        $this->actingAs($this->restricted)->post(route('products.stock-reset.store'), [])->assertForbidden();
    }

    public function test_password_phrase_preview_and_replay_are_enforced(): void
    {
        $preview = $this->preview();
        $this->submit($preview, ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->submit($preview, ['confirmation' => 'zero'])->assertSessionHasErrors('confirmation');
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->submit($preview)->assertSessionHasNoErrors();
        $this->submit($preview)->assertStatus(419);
    }

    public function test_changes_after_the_preview_prevent_a_stale_reset(): void
    {
        $preview = $this->preview();
        StockAdjustment::adjust($this->productA, 1, 'correction', actor: $this->admin);
        $this->submit($preview)->assertSessionHasErrors('reset');
        $this->assertSame(101, $this->productA->fresh()->stock);
        $this->assertSame(50, $this->productB->fresh()->stock);
    }

    public function test_active_holds_prevent_a_reset_instead_of_silently_invalidating_allocations(): void
    {
        $preview = $this->preview();
        app(StockReservationService::class)->reserve($this->productA, null, 2, 'core', 'reset-guard');
        $this->submit($preview)->assertSessionHasErrors('reset');
        $this->assertSame(100, $this->productA->fresh()->stock);
    }

    public function test_soft_deleted_products_and_inconsistent_bin_totals_are_zeroed_and_not_restored(): void
    {
        $this->productB->delete();
        DB::table('product_location_stocks')->where('product_id', $this->productA->id)->where('location_id', $this->locationA->id)->update(['quantity' => 120]);
        $service = app(InventoryResetService::class);
        $preview = $service->preview($this->admin);
        $service->reset($this->admin, $preview['signature'], 'Reset imported stock');
        $this->assertSame(0, Product::withTrashed()->findOrFail($this->productB->id)->stock);
        $this->assertSoftDeleted($this->productB);
        $this->assertSame(0, (int) DB::table('product_location_stocks')->sum('quantity'));
        $this->assertSame(0, $this->productA->fresh()->stock);
    }

    public function test_legacy_stock_in_a_deleted_location_is_written_off_without_restoring_the_location(): void
    {
        DB::table('product_locations')->where('id', $this->locationA->id)->update(['deleted_at' => now()]);
        $service = app(InventoryResetService::class);
        $preview = $service->preview($this->admin);
        $service->reset($this->admin, $preview['signature'], 'Clear legacy stock');
        $this->assertSame(0, $this->productA->fresh()->stock);
        $this->assertSame(0, (int) DB::table('product_location_stocks')->sum('quantity'));
        $this->assertSoftDeleted($this->locationA);
        $this->assertDatabaseHas('stock_adjustments', ['location_id' => $this->locationA->id, 'reason' => 'Administrator stock reset']);
    }

    public function test_a_failed_adjustment_rolls_back_every_stock_change(): void
    {
        $service = app(InventoryResetService::class);
        $preview = $service->preview($this->admin);
        $writes = 0;
        StockAdjustment::creating(function () use (&$writes): void {
            if (++$writes === 2) {
                throw new \RuntimeException('Simulated ledger failure');
            }
        });
        try {
            $service->reset($this->admin, $preview['signature'], 'Rollback check');
            $this->fail('Expected ledger failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated ledger failure', $e->getMessage());
        }
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertSame(50, $this->productB->fresh()->stock);
        $this->assertSame(150, (int) DB::table('product_location_stocks')->sum('quantity'));
        $this->assertDatabaseCount('stock_adjustments', 0);
    }
}
