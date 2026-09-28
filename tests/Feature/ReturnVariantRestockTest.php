<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrderItem;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ReturnOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A line sold as a variant decrements the VARIANT at order creation, so a
 * received return of that line must credit the variant back. It used to
 * credit the parent product: the variant stayed short forever while the
 * parent's on-hand grew by units it never lost.
 */
final class ReturnVariantRestockTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'RV Org', 'email' => 'rv@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@rv.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $location = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'A', 'code' => 'A', 'is_active' => true,
        ]);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'RV-1', 'name' => 'Shirt',
            'price' => 5, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0,
            'location_id' => $location->id, 'is_active' => true, 'has_variants' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id, 'organization_id' => $this->org->id,
            'sku' => 'RV-1-S', 'title' => 'S', 'option_values' => ['Size' => 'S'],
            'price' => 5, 'stock' => 10, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
        ]);
        $this->actingAs($this->admin);
    }

    private function variantOrder(int $qty): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'delivered', 'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'product_variant_id' => $this->variant->id,
                'quantity' => $qty,
                'unit_price' => 5.00,
            ]],
        ], $this->admin);
    }

    public function test_return_line_records_the_variant_of_its_order_line(): void
    {
        $order = $this->variantOrder(5);

        $return = app(ReturnOrderService::class)->create($this->org->id, $this->admin, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Too small',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 5, 'condition' => 'new', 'restock' => true]],
        ]);

        $this->assertSame($this->variant->id, (int) $return->items()->first()->product_variant_id);
    }

    public function test_receiving_a_variant_return_credits_the_variant_not_the_parent(): void
    {
        $order = $this->variantOrder(5);
        $this->assertSame(5, (int) $this->variant->fresh()->stock);
        $parentBefore = (int) $this->product->fresh()->stock;

        $service = app(ReturnOrderService::class);
        $return = $service->create($this->org->id, $this->admin, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Too small',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 5, 'condition' => 'new', 'restock' => true]],
        ]);
        $service->approve($return, $this->admin);
        $service->receive($return->fresh(), $this->admin);

        $this->assertSame(10, (int) $this->variant->fresh()->stock);
        $this->assertSame($parentBefore, (int) $this->product->fresh()->stock);
        $this->assertSame(1, StockAdjustment::where('type', 'return')->where('product_variant_id', $this->variant->id)->count());
    }

    public function test_a_legacy_return_line_without_a_variant_falls_back_to_the_order_line(): void
    {
        $order = $this->variantOrder(3);
        $parentBefore = (int) $this->product->fresh()->stock;

        $service = app(ReturnOrderService::class);
        $return = $service->create($this->org->id, $this->admin, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Legacy',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 3, 'condition' => 'new', 'restock' => true]],
        ]);
        // A row written before the column existed carries no variant.
        DB::table('return_order_items')->where('return_order_id', $return->id)->update(['product_variant_id' => null]);

        $service->approve($return, $this->admin);
        $service->receive($return->fresh(), $this->admin);

        $this->assertSame(10, (int) $this->variant->fresh()->stock);
        $this->assertSame($parentBefore, (int) $this->product->fresh()->stock);
    }

    public function test_backfill_migration_copies_the_variant_from_the_order_line(): void
    {
        $order = $this->variantOrder(2);
        $return = app(ReturnOrderService::class)->create($this->org->id, $this->admin, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Backfill',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 2, 'condition' => 'new', 'restock' => true]],
        ]);
        DB::table('return_order_items')->where('return_order_id', $return->id)->update(['product_variant_id' => null]);

        $migration = require database_path('migrations/2026_09_28_092357_add_product_variant_id_to_return_order_items_table.php');
        $migration->backfill();

        $this->assertSame($this->variant->id, (int) ReturnOrderItem::where('return_order_id', $return->id)->value('product_variant_id'));
    }
}
