<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\OrderItemBatchAllocation;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A line sold as a variant decrements the variant's stock, not the parent's,
 * so it must not consume the PARENT's serials or batches either. Order
 * creation used to allocate tracked units for every line, which marked the
 * parent's serials sold (or drew its batches down) for goods that came out of
 * the variant. The web edit path (replaceItems) already skipped variant lines.
 */
final class VariantLineTrackedAllocationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();

        $this->org = Organization::create([
            'name' => 'VT Org', 'email' => 'vt@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@vt.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->actingAs($this->admin);
    }

    private function trackedParent(string $tracking): array
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'VT-'.$tracking, 'name' => 'Tracked',
            'price' => 10, 'currency' => 'USD', 'stock' => 5, 'min_stock' => 0,
            'tracking_type' => $tracking, 'is_active' => true, 'has_variants' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'organization_id' => $this->org->id,
            'sku' => 'VT-'.$tracking.'-S', 'title' => 'S', 'option_values' => ['Size' => 'S'],
            'price' => 10, 'stock' => 10, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
        ]);

        return [$product, $variant];
    }

    private function sellVariant(Product $product, ProductVariant $variant, int $qty): void
    {
        app(OrderService::class)->create([
            'customer_name' => 'Acme',
            'items' => [[
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => $qty,
                'unit_price' => 10.00,
            ]],
        ], $this->admin);
    }

    public function test_a_variant_line_does_not_mark_the_parents_serials_sold(): void
    {
        [$product, $variant] = $this->trackedParent('serial');
        for ($i = 1; $i <= 5; $i++) {
            ProductSerial::create([
                'organization_id' => $this->org->id, 'product_id' => $product->id,
                'serial_number' => "VT-SN-{$i}", 'status' => 'available',
            ]);
        }

        $this->sellVariant($product, $variant, 2);

        $this->assertSame(8, (int) $variant->fresh()->stock);
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(5, ProductSerial::where('product_id', $product->id)->where('status', 'available')->count());
    }

    public function test_a_variant_line_does_not_draw_down_the_parents_batches(): void
    {
        [$product, $variant] = $this->trackedParent('batch');
        $batch = ProductBatch::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id,
            'batch_number' => 'VT-B1', 'quantity' => 5, 'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->sellVariant($product, $variant, 3);

        $this->assertSame(7, (int) $variant->fresh()->stock);
        $this->assertSame(5, (int) $batch->fresh()->quantity);
        $this->assertSame(0, OrderItemBatchAllocation::count());
    }
}
