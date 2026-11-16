<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\Role;
use App\Services\ProductLocationStockService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TransferSourceStockTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
    }

    public function test_transfer_only_staff_can_read_their_source_without_product_view_permission(): void
    {
        $role = Role::create(['name' => 'Transfer only', 'slug' => 'transfer-only', 'organization_id' => $this->organization->id, 'permissions' => ['transfer_stock']]);
        $this->restricted->roles()->sync([$role->id]);
        app(StockReservationService::class)->reserve($this->productA, null, 7, 'core', 'source-hold', locationId: $this->locationA->id);

        $this->actingAs($this->restricted)->getJson(route('stock-transfers.source-stock', ['location_id' => $this->locationA->id]))
            ->assertOk()->assertExactJson(['data' => [['product_id' => $this->productA->id, 'product_variant_id' => null, 'available' => 53]]]);
        $this->assertSame(100, $this->productA->fresh()->stock);
    }

    public function test_source_cannot_cross_warehouse_or_organization_boundaries(): void
    {
        $this->actingAs($this->restricted)->getJson(route('stock-transfers.source-stock', ['location_id' => $this->locationB->id]))->assertForbidden();
        $other = ProductLocation::factory()->create();
        $this->actingAs($this->admin)->getJson(route('stock-transfers.source-stock', ['location_id' => $other->id]))->assertNotFound();
        $this->actingAs($this->admin)->getJson(route('stock-transfers.source-stock'))->assertUnprocessable();
        $this->actingAs($this->admin)->getJson(route('stock-transfers.source-stock', ['location_id' => 'invalid']))->assertUnprocessable();
    }

    public function test_variants_report_their_own_source_availability(): void
    {
        $this->productA->update(['has_variants' => true]);
        $variant = ProductVariant::create(['organization_id' => $this->organization->id, 'product_id' => $this->productA->id, 'sku' => 'BLUE-M', 'option_values' => ['Color' => 'Blue'], 'stock' => 20, 'is_active' => true]);
        DB::transaction(fn () => app(ProductLocationStockService::class)->move($variant, $this->locationA->id, $this->locationB->id, 7));
        $this->actingAs($this->restricted)->getJson(route('stock-transfers.source-stock', ['location_id' => $this->locationA->id]))
            ->assertOk()->assertExactJson(['data' => [['product_id' => $this->productA->id, 'product_variant_id' => $variant->id, 'available' => 13]]]);
    }

    public function test_permission_is_required_even_for_an_accessible_source(): void
    {
        $this->restricted->roles()->detach();
        $this->actingAs($this->restricted)->getJson(route('stock-transfers.source-stock', ['location_id' => $this->locationA->id]))->assertForbidden();
    }
}
