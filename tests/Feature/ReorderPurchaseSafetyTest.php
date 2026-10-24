<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

final class ReorderPurchaseSafetyTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private User $admin;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->markInstalled();
        Mail::fake();
        Notification::fake();
        $org = $this->makeOrganization();
        $org->update(['currency' => 'CAD']);
        $this->admin = $this->makeAdmin($org);
        $this->actingAs($this->admin);
        $this->supplier = Supplier::create(['organization_id' => $org->id, 'name' => 'Canadian supplier', 'currency' => 'CAD', 'is_active' => true]);
        $this->product = $this->makeProduct($org, ['stock' => 3, 'min_stock' => 10, 'reorder_point' => 10, 'reorder_quantity' => 50, 'currency' => 'CAD']);
        $this->product->suppliers()->attach($this->supplier, ['is_primary' => true, 'cost_price' => '5.50']);
    }

    private function existingOrder(string $status, int $received): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'organization_id' => $this->product->organization_id, 'supplier_id' => $this->supplier->id,
            'po_number' => PurchaseOrder::generatePONumber($this->product->organization_id),
            'status' => $status, 'currency' => 'CAD', 'order_date' => now(),
            'subtotal' => 275, 'tax' => 0, 'shipping' => 0, 'total' => 275,
        ]);
        $po->items()->create([
            'product_id' => $this->product->id, 'product_name' => $this->product->name, 'sku' => $this->product->sku,
            'quantity_ordered' => 50, 'quantity_received' => $received, 'unit_cost' => '5.50', 'subtotal' => 275, 'tax' => 0, 'total' => 275,
        ]);

        return $po;
    }

    public function test_outstanding_units_on_a_partial_order_prevent_another_automatic_order(): void
    {
        $this->existingOrder('partial', 2);
        $this->artisan('inventory:check-reorder-points')->assertSuccessful();
        $this->assertDatabaseCount('purchase_orders', 1);
    }

    public function test_a_fully_received_line_on_a_partial_order_does_not_block_reordering(): void
    {
        $this->existingOrder('partial', 50);
        $this->artisan('inventory:check-reorder-points')->assertSuccessful();
        $this->assertDatabaseCount('purchase_orders', 2);
    }

    public static function closedOrders(): array
    {
        return ['cancelled' => ['cancelled', false], 'received' => ['received', false], 'deleted draft' => ['draft', true]];
    }

    #[DataProvider('closedOrders')]
    public function test_closed_or_deleted_orders_do_not_block_replenishment(string $status, bool $deleted): void
    {
        $po = $this->existingOrder($status, 0);
        if ($deleted) {
            $po->delete();
        }
        $this->artisan('inventory:check-reorder-points')->assertSuccessful();
        $this->assertSame($deleted ? 1 : 2, PurchaseOrder::count());
    }

    public static function currencyCases(): array
    {
        return [
            'automatic CAD' => [false, 'CAD', 'CAD'], 'quick CAD' => [true, 'CAD', 'CAD'],
            'automatic EUR' => [false, 'EUR', 'EUR'], 'quick EUR' => [true, 'EUR', 'EUR'],
            'automatic legacy blank' => [false, '', 'CAD'], 'quick legacy blank' => [true, '', 'CAD'],
        ];
    }

    #[DataProvider('currencyCases')]
    public function test_generated_orders_use_supplier_currency_with_organization_fallback(bool $quick, string $currency, string $expected): void
    {
        DB::table('suppliers')->where('id', $this->supplier->id)->update(['currency' => $currency]);
        if ($quick) {
            $this->post(route('purchase-orders.quick-reorder'), ['product_ids' => [$this->product->id]])
                ->assertSessionHasNoErrors();
        } else {
            $this->artisan('inventory:check-reorder-points')->assertSuccessful();
        }
        $po = PurchaseOrder::sole();
        $this->assertSame($expected, $po->currency);
        $this->assertEquals('275.00', $po->total);
        $this->assertEquals('5.50', $po->items()->sole()->unit_cost);
    }
}
