<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A line sold as a variant decrements the VARIANT's stock at order creation,
 * so restocking on cancel/delete must credit the variant, not the parent
 * product. Crediting the parent would leave the variant permanently depleted
 * while inflating the parent's on-hand count.
 */
final class OrderVariantRestockTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $creator;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->org = Organization::create([
            'name' => 'Var', 'email' => 'var@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->creator = User::create([
            'name' => 'Creator', 'email' => 'creator@var.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'VP-1', 'name' => 'Variable', 'price' => 5.00, 'currency' => 'USD',
            'stock' => 100, 'min_stock' => 0, 'is_active' => true, 'has_variants' => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'organization_id' => $this->org->id,
            'sku' => 'VP-1-S', 'title' => 'S', 'option_values' => ['Size' => 'S'],
            'price' => 5.00, 'stock' => 10, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
        ]);

        // The restock paths stamp the acting user onto the ledger row.
        $this->actingAs($this->creator);
    }

    private function makeVariantOrder(int $qty)
    {
        return app(OrderService::class)->create([
            'customer_name' => 'V Customer',
            'items' => [[
                'product_id' => $this->product->id,
                'product_variant_id' => $this->variant->id,
                'quantity' => $qty,
                'unit_price' => 5.00,
            ]],
        ], $this->creator);
    }

    public function test_restock_for_deletion_credits_the_variant_not_the_parent(): void
    {
        $order = $this->makeVariantOrder(3);

        // Creation decremented the variant, not the parent.
        $this->assertSame(7, $this->variant->fresh()->stock);
        $parentBefore = $this->product->fresh()->stock;

        app(OrderService::class)->restockForDeletion($order);

        $this->assertSame(10, $this->variant->fresh()->stock);
        $this->assertSame($parentBefore, $this->product->fresh()->stock);
    }

    public function test_cancel_credits_the_variant_not_the_parent(): void
    {
        $order = $this->makeVariantOrder(4);

        $this->assertSame(6, $this->variant->fresh()->stock);
        $parentBefore = $this->product->fresh()->stock;

        app(OrderService::class)->cancel($order);

        $this->assertSame(10, $this->variant->fresh()->stock);
        $this->assertSame($parentBefore, $this->product->fresh()->stock);
    }
}
