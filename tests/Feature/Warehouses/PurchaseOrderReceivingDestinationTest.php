<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\ReceivePurchaseOrderTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\VariantLocationStock;
use App\Models\Inventory\VariantReplenishmentPolicy;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\Warehouse;
use App\Services\PurchaseOrderService;
use App\Services\VariantReplenishmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PurchaseOrderReceivingDestinationTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->setUpWarehouseAccess();
    }

    public function test_web_receiving_books_only_the_selected_bin_without_changing_the_product_location(): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productA);
        $destination = $this->extraLocation($this->warehouseB);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.process-receiving', $po), [
                'items' => [$this->receipt($line, 4, $destination->id)],
            ])
            ->assertRedirect(route('purchase-orders.show', $po))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertProductReceipt($po, $line, $this->productA, $destination, 4, 104);
        $this->assertSame(60, $this->bin($this->productA, $this->locationA));
        $this->assertSame(40, $this->bin($this->productA, $this->locationB));
        $this->assertSame($this->locationA->id, $this->productA->fresh()->location_id);
        $this->assertNull($line->fresh()->receiving_location_id);
    }

    public function test_a_restricted_receiver_can_choose_an_accessible_bin_when_the_product_primary_is_elsewhere(): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productB);
        Sanctum::actingAs($this->restricted);

        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [$this->receipt($line, 4, $this->locationA->id)],
        ])->assertOk();

        $this->assertProductReceipt($po, $line, $this->productB, $this->locationA, 4, 54);
        $this->assertSame(50, $this->bin($this->productB, $this->locationB));
        $this->assertSame($this->locationB->id, $this->productB->fresh()->location_id);
    }

    public function test_a_restricted_receiver_cannot_choose_an_inaccessible_bin_even_when_the_primary_is_accessible(): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productA);
        Sanctum::actingAs($this->restricted);

        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [$this->receipt($line, 4, $this->locationB->id)],
        ])->assertForbidden();

        $this->assertUnreceived($po, $line);
        $this->assertSame(100, (int) $this->productA->fresh()->stock);
        $this->assertSame(40, $this->bin($this->productA, $this->locationB));
    }

    public function test_direct_service_access_is_checked_against_the_selected_destination(): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productB);
        $this->actingAs($this->restricted);

        $this->assertSame(1, app(PurchaseOrderService::class)->receive($po, $this->restricted, [
            $this->receipt($line, 3, $this->locationA->id),
        ]));

        $this->assertProductReceipt($po, $line, $this->productB, $this->locationA, 3, 53);
    }

    public function test_numeric_string_destination_ids_are_normalized_by_the_service(): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productA);
        $destination = $this->extraLocation($this->warehouseB);
        $this->actingAs($this->admin);

        app(PurchaseOrderService::class)->receive($po, $this->admin, [
            $this->receipt($line, 3, (string) $destination->id),
        ]);

        $this->assertProductReceipt($po, $line, $this->productA, $destination, 3, 103);
    }

    public function test_direct_service_rejects_an_inaccessible_selected_bin(): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productA);
        $this->actingAs($this->restricted);

        try {
            app(PurchaseOrderService::class)->receive($po, $this->restricted, [
                $this->receipt($line, 3, $this->locationB->id),
            ]);
            $this->fail('The service must authorize the selected receiving bin.');
        } catch (AuthorizationException) {
            $this->assertUnreceived($po, $line);
        }
    }

    public function test_zero_quantity_lines_do_not_require_access_to_an_unused_destination(): void
    {
        [$po, $first] = $this->makeSentPurchaseOrder($this->productA);
        $second = $po->items()->create([
            'product_id' => $this->productB->id, 'product_name' => $this->productB->name, 'sku' => $this->productB->sku,
            'quantity_ordered' => 10, 'quantity_received' => 0, 'unit_cost' => 5, 'subtotal' => 50, 'tax' => 0, 'total' => 50,
        ]);
        Sanctum::actingAs($this->restricted);

        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [
                $this->receipt($first, 3, $this->locationA->id),
                $this->receipt($second, 0, $this->locationB->id),
            ],
        ])->assertOk();

        $this->assertProductReceipt($po, $first, $this->productA, $this->locationA, 3, 103, 63);
        $this->assertSame(0, $second->fresh()->quantity_received);
        $this->assertSame(50, (int) $this->productB->fresh()->stock);
    }

    public function test_malformed_destination_ids_are_rejected_even_for_zero_quantity_lines(): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productA);
        $this->actingAs($this->admin);

        try {
            app(PurchaseOrderService::class)->receive($po, $this->admin, [$this->receipt($line, 0, [$this->locationA->id])]);
            $this->fail('Malformed receiving destination IDs must always fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.receiving_location_id', $exception->errors());
        }

        $this->assertUnreceived($po, $line);
    }

    #[DataProvider('defaultDestinations')]
    public function test_null_or_omitted_destination_preserves_the_existing_receiving_default(bool $planned, bool $explicitNull): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productA);
        if ($planned) {
            $line->update(['receiving_location_id' => $this->locationB->id]);
        }
        $receipt = ['id' => $line->id, 'quantity_to_receive' => 3];
        if ($explicitNull) {
            $receipt['receiving_location_id'] = null;
        }
        $this->actingAs($this->admin);

        $this->assertSame(1, app(PurchaseOrderService::class)->receive($po, $this->admin, [$receipt]));

        $destination = $planned ? $this->locationB : $this->locationA;
        $this->assertProductReceipt($po, $line, $this->productA, $destination, 3, 103, $planned ? 43 : 63);
        $this->assertEquals($planned ? $this->locationB->id : null, $line->fresh()->receiving_location_id);
        $this->assertSame($this->locationA->id, $this->productA->fresh()->location_id);
    }

    public static function defaultDestinations(): array
    {
        return [
            'manual omitted' => [false, false],
            'manual null' => [false, true],
            'planned omitted' => [true, false],
            'planned null' => [true, true],
        ];
    }

    public function test_a_planned_line_can_use_another_bin_in_its_warehouse_without_changing_the_next_partial_default(): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productA);
        $line->update(['receiving_location_id' => $this->locationB->id]);
        $overflow = $this->extraLocation($this->warehouseB);
        $this->actingAs($this->admin);
        $service = app(PurchaseOrderService::class);

        $service->receive($po, $this->admin, [$this->receipt($line, 3, $overflow->id)]);
        $service->receive($po->fresh(), $this->admin, [['id' => $line->id, 'quantity_to_receive' => 2]]);

        $this->assertSame(3, $this->bin($this->productA, $overflow));
        $this->assertSame(42, $this->bin($this->productA, $this->locationB));
        $this->assertSame(60, $this->bin($this->productA, $this->locationA));
        $this->assertSame(105, (int) $this->productA->fresh()->stock);
        $this->assertSame(5, $line->fresh()->quantity_received);
        $this->assertEquals($this->locationB->id, $line->fresh()->receiving_location_id);
        $this->assertSame([$overflow->id, $this->locationB->id], StockAdjustment::orderBy('id')->pluck('location_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_planned_line_cannot_be_redirected_to_a_different_warehouse(): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productA);
        $line->update(['receiving_location_id' => $this->locationB->id]);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [$this->receipt($line, 3, $this->locationA->id)],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.receiving_location_id');

        $this->assertUnreceived($po, $line);
        $this->assertEquals($this->locationB->id, $line->fresh()->receiving_location_id);
    }

    public function test_variant_receipts_book_the_variant_at_the_selected_bin_and_preserve_parent_stock(): void
    {
        [$po, $line, $variant] = $this->variantLine();
        $destination = $this->extraLocation($this->warehouseB);
        $this->actingAs($this->admin);

        app(PurchaseOrderService::class)->receive($po, $this->admin, [$this->receipt($line, 4, $destination->id)]);

        $this->assertSame(16, (int) $variant->fresh()->stock);
        $this->assertSame(100, (int) $this->productA->fresh()->stock);
        $this->assertDatabaseHas('variant_location_stocks', [
            'product_variant_id' => $variant->id, 'location_id' => $destination->id, 'quantity' => 4,
        ]);
        $this->assertDatabaseHas('variant_location_stocks', [
            'product_variant_id' => $variant->id, 'location_id' => $this->locationA->id, 'quantity' => 10,
        ]);
        $this->assertDatabaseHas('variant_location_stocks', [
            'product_variant_id' => $variant->id, 'location_id' => $this->locationB->id, 'quantity' => 2,
        ]);
        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->productA->id, 'product_variant_id' => $variant->id,
            'location_id' => $destination->id, 'adjustment_quantity' => 4,
        ]);
        $this->assertNull($line->fresh()->receiving_location_id);
    }

    public function test_replenishment_receipts_keep_the_planned_warehouse_and_policy(): void
    {
        [$unused, , $variant] = $this->variantLine();
        $unused->delete();
        $supplierId = $unused->supplier_id;
        $this->actingAs($this->admin);
        $replenishment = app(VariantReplenishmentService::class);
        $policy = $replenishment->save($this->admin, [
            'product_variant_id' => $variant->id, 'warehouse_id' => $this->warehouseB->id,
            'location_id' => $this->locationB->id, 'supplier_id' => $supplierId,
            'reorder_point' => 5, 'target_stock' => 10, 'minimum_order_quantity' => 1,
            'unit_cost' => 5, 'currency' => 'USD',
        ]);
        $po = $replenishment->createDraft($this->admin, $policy);
        $this->assertNotNull($po);
        $po->update(['status' => PurchaseOrder::STATUS_SENT]);
        $line = $po->items()->sole();
        $overflow = $this->extraLocation($this->warehouseB);

        app(PurchaseOrderService::class)->receive($po, $this->admin, [$this->receipt($line, 3, $overflow->id)]);

        $this->assertEquals($policy->id, $line->fresh()->replenishment_policy_id);
        $this->assertEquals($this->locationB->id, $line->fresh()->receiving_location_id);
        $this->assertSame($this->locationB->id, VariantReplenishmentPolicy::findOrFail($policy->id)->location_id);
        $this->assertDatabaseHas('variant_location_stocks', [
            'product_variant_id' => $variant->id, 'location_id' => $overflow->id, 'quantity' => 3,
        ]);

        try {
            app(PurchaseOrderService::class)->receive($po->fresh(), $this->admin, [$this->receipt($line, 1, $this->locationA->id)]);
            $this->fail('A replenishment line must keep its planned warehouse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.receiving_location_id', $exception->errors());
        }
        $this->assertSame(3, $line->fresh()->quantity_received);
    }

    #[DataProvider('invalidDestinations')]
    public function test_invalid_destinations_are_rejected_through_the_api_and_service(string $kind, bool $direct): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productA);
        $destination = $this->invalidDestination($kind);
        $items = [$this->receipt($line, 3, $destination)];
        $this->actingAs($this->admin);

        if ($direct) {
            try {
                app(PurchaseOrderService::class)->receive($po, $this->admin, $items);
                $this->fail('Invalid receiving destinations must be rejected by the service.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('items.0.receiving_location_id', $exception->errors());
            }
        } else {
            Sanctum::actingAs($this->admin);
            $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", ['items' => $items])
                ->assertUnprocessable()->assertJsonValidationErrors('items.0.receiving_location_id');
        }

        $this->assertUnreceived($po, $line);
        $this->assertSame(100, (int) $this->productA->fresh()->stock);
    }

    public static function invalidDestinations(): array
    {
        $cases = [];
        foreach (['foreign', 'deleted', 'inactive', 'inactive warehouse', 'missing', 'text', 'array', 'fraction'] as $kind) {
            $cases[$kind.' API'] = [$kind, false];
            $cases[$kind.' service'] = [$kind, true];
        }

        return $cases;
    }

    public function test_an_invalid_later_destination_rolls_back_the_entire_delivery(): void
    {
        [$po, $first] = $this->makeSentPurchaseOrder($this->productA);
        $second = $po->items()->create([
            'product_id' => $this->productB->id, 'product_name' => $this->productB->name, 'sku' => $this->productB->sku,
            'quantity_ordered' => 10, 'quantity_received' => 0, 'unit_cost' => 5, 'subtotal' => 50, 'tax' => 0, 'total' => 50,
        ]);
        $destination = $this->extraLocation($this->warehouseA);
        $invalid = $this->extraLocation($this->warehouseA, ['is_active' => false]);
        $this->actingAs($this->admin);

        try {
            app(PurchaseOrderService::class)->receive($po, $this->admin, [
                $this->receipt($first, 3, $destination->id),
                $this->receipt($second, 3, $invalid->id),
            ]);
            $this->fail('An invalid receiving bin must reject the delivery.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.1.receiving_location_id', $exception->errors());
        }

        $this->assertUnreceived($po, $first);
        $this->assertSame(0, $second->fresh()->quantity_received);
        $this->assertSame(100, (int) $this->productA->fresh()->stock);
        $this->assertSame(50, (int) $this->productB->fresh()->stock);
        $this->assertSame(0, $this->bin($this->productA, $destination));
    }

    public function test_graphql_can_select_a_receiving_destination(): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productB);

        $this->actingAs($this->restricted)->postJson('/graphql', [
            'query' => "mutation { receivePurchaseOrder(id: {$po->id}, items: [{id: {$line->id}, quantity_to_receive: 3, receiving_location_id: {$this->locationA->id}}]) { id status } }",
        ])->assertOk()->assertJsonMissingPath('errors')->assertJsonPath('data.receivePurchaseOrder.status', 'partial');

        $this->assertProductReceipt($po, $line, $this->productB, $this->locationA, 3, 53);
    }

    public function test_mcp_can_select_a_receiving_destination(): void
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productB);

        InventorosServer::actingAs($this->restricted)
            ->tool(ReceivePurchaseOrderTool::class, [
                'id' => $po->id, 'items' => [$this->receipt($line, 3, $this->locationA->id)],
            ])->assertOk();

        $this->assertProductReceipt($po, $line, $this->productB, $this->locationA, 3, 53);
    }

    public function test_receiving_page_lists_only_active_accessible_destination_bins(): void
    {
        [$po] = $this->makeSentPurchaseOrder($this->productA);
        $extra = $this->extraLocation($this->warehouseA);
        $this->extraLocation($this->warehouseA, ['is_active' => false]);
        $deleted = $this->extraLocation($this->warehouseA);
        $deleted->delete();
        $inactiveWarehouse = Warehouse::factory()->create([
            'organization_id' => $this->organization->id, 'is_active' => false,
        ]);
        $this->extraLocation($inactiveWarehouse);
        $expected = [$this->locationA->id, $extra->id];
        sort($expected);

        $this->actingAs($this->restricted)->get(route('purchase-orders.receive', $po))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PurchaseOrders/Receive')
            ->where('receivingLocations', fn ($locations) => collect($locations)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all() === $expected));
    }

    private function receipt(PurchaseOrderItem $line, int $quantity, mixed $locationId): array
    {
        return ['id' => $line->id, 'quantity_to_receive' => $quantity, 'receiving_location_id' => $locationId];
    }

    private function extraLocation(Warehouse $warehouse, array $overrides = []): ProductLocation
    {
        return ProductLocation::create($overrides + [
            'organization_id' => $this->organization->id, 'warehouse_id' => $warehouse->id,
            'name' => 'Receiving '.uniqid(), 'code' => 'RCV-'.uniqid(), 'is_active' => true,
        ]);
    }

    private function invalidDestination(string $kind): mixed
    {
        if ($kind === 'foreign') {
            $other = Organization::create(['name' => 'Other', 'email' => 'other@example.test']);

            return ProductLocation::create([
                'organization_id' => $other->id, 'name' => 'Other bin', 'code' => 'OTHER', 'is_active' => true,
            ])->id;
        }
        if ($kind === 'inactive warehouse') {
            return $this->extraLocation(Warehouse::factory()->create([
                'organization_id' => $this->organization->id, 'is_active' => false,
            ]))->id;
        }
        if ($kind === 'inactive' || $kind === 'deleted') {
            $location = $this->extraLocation($this->warehouseA, ['is_active' => $kind !== 'inactive']);
            if ($kind === 'deleted') {
                $location->delete();
            }

            return $location->id;
        }

        return match ($kind) {
            'missing' => 999999,
            'text' => 'not-a-location',
            'array' => [$this->locationA->id],
            'fraction' => 1.5,
        };
    }

    private function bin(Product $product, ProductLocation $location): int
    {
        return (int) ProductLocationStock::where('product_id', $product->id)->where('location_id', $location->id)->value('quantity');
    }

    private function assertUnreceived(PurchaseOrder $po, PurchaseOrderItem $line): void
    {
        $this->assertSame(0, $line->fresh()->quantity_received);
        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    private function assertProductReceipt(PurchaseOrder $po, PurchaseOrderItem $line, Product $product, ProductLocation $destination, int $quantity, int $stock, ?int $binQuantity = null): void
    {
        $this->assertSame($quantity, $line->fresh()->quantity_received);
        $this->assertSame($stock, (int) $product->fresh()->stock);
        $this->assertSame($binQuantity ?? $quantity, $this->bin($product, $destination));
        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $po->fresh()->status);
        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $product->id, 'location_id' => $destination->id, 'type' => 'purchase',
            'adjustment_quantity' => $quantity, 'reference_type' => PurchaseOrder::class, 'reference_id' => $po->id,
        ]);
    }

    /** @return array{PurchaseOrder, PurchaseOrderItem, ProductVariant} */
    private function variantLine(): array
    {
        [$po, $line] = $this->makeSentPurchaseOrder($this->productA);
        $this->productA->update(['has_variants' => true]);
        $variant = ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->productA->id,
            'sku' => 'ALPHA-SMALL', 'title' => 'Small', 'option_values' => ['Size' => 'Small'],
            'stock' => 12, 'price' => 10, 'is_active' => true,
        ]);
        foreach ([[$this->locationA, 10], [$this->locationB, 2]] as [$location, $quantity]) {
            VariantLocationStock::updateOrCreate([
                'organization_id' => $this->organization->id, 'product_variant_id' => $variant->id,
                'location_id' => $location->id,
            ], ['quantity' => $quantity]);
        }
        $line->update(['product_variant_id' => $variant->id, 'sku' => $variant->sku]);

        return [$po, $line, $variant];
    }
}
