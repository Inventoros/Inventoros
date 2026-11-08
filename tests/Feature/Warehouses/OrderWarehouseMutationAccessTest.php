<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreateOrderTool;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\VariantLocationStock;
use App\Models\Order\Order;
use App\Services\OrderService;
use App\Services\StockReservationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OrderWarehouseMutationAccessTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
        $role = $this->restricted->roles()->firstOrFail();
        $role->update(['permissions' => array_merge($this->stockPermissions, ['create_orders', 'edit_orders', 'delete_orders', 'view_orders'])]);
        $this->restricted->unsetRelation('roles');
    }

    private function payload(Product $product, int $quantity = 2): array
    {
        return [
            'customer_name' => 'Warehouse boundary regression', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 10]],
        ];
    }

    private function bin(Product $product, int $locationId): int
    {
        return (int) ProductLocationStock::where('product_id', $product->id)->where('location_id', $locationId)->value('quantity');
    }

    private function assertRefused(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('A warehouse-restricted stock mutation must be refused.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertBravoUnchanged(): void
    {
        $this->assertSame(50, $this->productB->fresh()->stock);
        $this->assertSame(50, $this->bin($this->productB, $this->locationB->id));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_rest_cannot_consume_stock_from_an_unassigned_warehouse(): void
    {
        Sanctum::actingAs($this->restricted, ['*']);
        $this->postJson('/api/v1/orders', $this->payload($this->productB))->assertForbidden();
        $this->assertBravoUnchanged();
    }

    public function test_web_cannot_name_an_unassigned_fulfilling_warehouse(): void
    {
        $this->actingAs($this->restricted)->postJson(route('orders.store'), $this->payload($this->productB) + [
            'warehouse_id' => $this->warehouseB->id,
        ])->assertForbidden();
        $this->assertBravoUnchanged();
    }

    public function test_graphql_cannot_consume_stock_from_an_unassigned_warehouse(): void
    {
        Sanctum::actingAs($this->restricted, ['*']);
        $response = $this->postJson('/graphql', ['query' => sprintf(
            'mutation { createOrder(customer_name: "Boundary", items: [{ product_id: %d, quantity: 2, unit_price: 10 }]) { id } }',
            $this->productB->id,
        )]);
        $this->assertNotEmpty($response->json('errors'));
        $this->assertBravoUnchanged();
    }

    public function test_mcp_cannot_consume_stock_from_an_unassigned_warehouse(): void
    {
        InventorosServer::actingAs($this->restricted)->tool(CreateOrderTool::class, $this->payload($this->productB))
            ->assertHasErrors(['access']);
        $this->assertBravoUnchanged();
    }

    public function test_mixed_product_draws_only_from_assigned_bins_even_when_another_warehouse_has_higher_priority(): void
    {
        $this->warehouseB->update(['priority' => 100]);
        app(OrderService::class)->create($this->payload($this->productA, 5), $this->restricted);
        $this->assertSame(55, $this->bin($this->productA, $this->locationA->id));
        $this->assertSame(40, $this->bin($this->productA, $this->locationB->id));
        $this->assertSame(95, $this->productA->fresh()->stock);
    }

    public function test_restricted_shortfall_rolls_back_instead_of_falling_back_to_another_warehouse(): void
    {
        $this->assertRefused(fn () => app(OrderService::class)->create($this->payload($this->productA, 61), $this->restricted));
        $this->assertSame(60, $this->bin($this->productA, $this->locationA->id));
        $this->assertSame(40, $this->bin($this->productA, $this->locationB->id));
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_a_reserved_foreign_bin_cannot_be_claimed_by_a_restricted_actor(): void
    {
        $hold = app(StockReservationService::class)->reserve($this->productA, null, 2, 'audit', 'foreign-bin', locationId: $this->locationB->id);
        $this->assertRefused(fn () => app(OrderService::class)->create($this->payload($this->productA) + [
            'reservation_owner' => 'audit', 'reservation_reference' => 'foreign-bin',
        ], $this->restricted));
        $this->assertNull($hold->fresh()->consumed_at);
        $this->assertTrue($hold->newQuery()->active()->whereKey($hold->id)->exists());
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_available_assigned_bins_exclude_other_reservations(): void
    {
        app(StockReservationService::class)->reserve($this->productA, null, 59, 'audit', 'held-own-bin', locationId: $this->locationA->id);
        $this->assertRefused(fn () => app(OrderService::class)->create($this->payload($this->productA), $this->restricted));
        $this->assertSame(60, $this->bin($this->productA, $this->locationA->id));
        $this->assertSame(40, $this->bin($this->productA, $this->locationB->id));
    }

    public function test_variants_use_the_same_bin_boundary_and_rollback_on_shortfall(): void
    {
        $this->productA->update(['has_variants' => true]);
        $variant = ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->productA->id,
            'sku' => 'BOUNDARY-VARIANT', 'stock' => 20, 'is_active' => true, 'option_values' => [],
        ]);
        foreach ([[$this->locationA, 5], [$this->locationB, 15]] as [$location, $quantity]) {
            VariantLocationStock::updateOrCreate(['product_variant_id' => $variant->id, 'location_id' => $location->id],
                ['organization_id' => $this->organization->id, 'quantity' => $quantity]);
        }
        $data = $this->payload($this->productA, 6);
        $data['items'][0]['product_variant_id'] = $variant->id;
        $this->assertRefused(fn () => app(OrderService::class)->create($data, $this->restricted));
        $this->assertSame(20, $variant->fresh()->stock);
        $data['items'][0]['quantity'] = 3;
        app(OrderService::class)->create($data, $this->restricted);
        $this->assertSame(17, $variant->fresh()->stock);
        $this->assertSame(15, (int) $variant->locationStocks()->where('location_id', $this->locationB->id)->value('quantity'));
        $this->assertSame(100, $this->productA->fresh()->stock);
    }

    public function test_cancellation_cannot_restock_another_warehouses_product(): void
    {
        $order = app(OrderService::class)->create($this->payload($this->productB), $this->admin);
        Sanctum::actingAs($this->restricted, ['*']);
        $this->putJson("/api/v1/orders/{$order->id}", ['status' => 'cancelled'])->assertForbidden();
        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame(48, $this->productB->fresh()->stock);
        $this->assertSame(48, $this->bin($this->productB, $this->locationB->id));
    }

    public function test_line_replacement_cannot_consume_foreign_stock_and_preserves_original_lines(): void
    {
        ProductLocationStock::where('product_id', $this->productA->id)->where('location_id', $this->locationB->id)->delete();
        $this->productA->update(['stock' => 60]);
        $order = app(OrderService::class)->create($this->payload($this->productA), $this->restricted);
        $itemId = $order->items()->firstOrFail()->id;
        $this->assertRefused(fn () => DB::transaction(fn () => app(OrderService::class)->replaceItems(
            $order, $this->payload($this->productB)['items'], $this->restricted,
        )));
        $this->assertSame([$itemId], $order->items()->pluck('id')->all());
        $this->assertSame(58, $this->productA->fresh()->stock);
        $this->assertSame(58, $this->bin($this->productA, $this->locationA->id));
        $this->assertSame(50, $this->productB->fresh()->stock);
    }

    public static function trackedTypes(): array
    {
        return [['serial'], ['batch']];
    }

    #[DataProvider('trackedTypes')]
    public function test_unlocated_tracked_stock_spanning_warehouses_requires_an_unrestricted_actor(string $trackingType): void
    {
        $this->productA->update(['tracking_type' => $trackingType]);
        $this->assertRefused(fn () => app(OrderService::class)->create($this->payload($this->productA), $this->restricted));
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
    }

    #[DataProvider('trackedTypes')]
    public function test_tracked_allocation_still_works_when_all_stock_locations_are_assigned(string $trackingType): void
    {
        ProductLocationStock::where('product_id', $this->productA->id)->where('location_id', $this->locationB->id)->delete();
        $this->productA->update(['tracking_type' => $trackingType, 'stock' => 60]);
        if ($trackingType === 'serial') {
            foreach (['BOUNDARY-S1', 'BOUNDARY-S2'] as $number) {
                ProductSerial::create(['organization_id' => $this->organization->id, 'product_id' => $this->productA->id,
                    'serial_number' => $number, 'status' => 'available']);
            }
        } else {
            ProductBatch::create(['organization_id' => $this->organization->id, 'product_id' => $this->productA->id,
                'batch_number' => 'BOUNDARY-BATCH', 'quantity' => 2]);
        }
        app(OrderService::class)->create($this->payload($this->productA), $this->restricted);
        $this->assertSame(58, $this->productA->fresh()->stock);
        if ($trackingType === 'serial') {
            $this->assertSame(2, ProductSerial::where('product_id', $this->productA->id)->where('status', 'sold')->count());
        } else {
            $this->assertSame(0, (int) ProductBatch::where('product_id', $this->productA->id)->value('quantity'));
        }
    }

    public function test_unrestricted_staff_keep_cross_warehouse_fallback(): void
    {
        app(OrderService::class)->create($this->payload($this->productA, 80) + ['warehouse_id' => $this->warehouseA->id], $this->admin);
        $this->assertSame(0, $this->bin($this->productA, $this->locationA->id));
        $this->assertSame(20, $this->bin($this->productA, $this->locationB->id));
        $this->assertSame(20, $this->productA->fresh()->stock);
    }

    public function test_staff_assigned_to_multiple_warehouses_can_use_all_of_their_bins(): void
    {
        $this->warehouseB->users()->attach($this->restricted->id);
        app(OrderService::class)->create($this->payload($this->productA, 80) + ['warehouse_id' => $this->warehouseA->id], $this->restricted);
        $this->assertSame(0, $this->bin($this->productA, $this->locationA->id));
        $this->assertSame(20, $this->bin($this->productA, $this->locationB->id));
    }

    public function test_restocking_mixed_warehouse_sources_requires_unrestricted_staff_until_source_bins_are_recorded(): void
    {
        $order = app(OrderService::class)->create($this->payload($this->productA), $this->restricted);
        $this->assertRefused(fn () => app(OrderService::class)->cancel($order, $this->restricted));
        $this->assertSame(98, $this->productA->fresh()->stock);
        $this->assertSame('pending', $order->fresh()->status->value);
        app(OrderService::class)->cancel($order, $this->admin);
        $this->assertSame(100, $this->productA->fresh()->stock);
    }
}
