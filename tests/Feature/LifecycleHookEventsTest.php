<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-6 (events follow-up) — the `product_created` action hook fires from the
 * model observer, so plugins observe product creation on every surface (web,
 * REST, GraphQL, MCP), not just the Inertia controller path.
 *
 * `order_created` is deliberately NOT promoted the same way: orders are built
 * in two steps (Order::create then items inserted), so a model-`created` hook
 * would fire on an itemless order. Promoting it correctly means firing after
 * the full aggregate is assembled in OrderService — tracked as a follow-up.
 */
class LifecycleHookEventsTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Hook Org', 'email' => 'hook@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
    }

    public function test_product_created_hook_fires_on_model_creation(): void
    {
        $captured = null;
        add_action('product_created', function (Product $product) use (&$captured): void {
            $captured = $product->sku;
        });

        // Created directly via the model — i.e. the path every surface shares,
        // not the web controller. The hook must still fire.
        Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'HOOK-1', 'name' => 'Hook Product',
            'price' => 1, 'currency' => 'USD', 'stock' => 1, 'min_stock' => 0, 'is_active' => true,
        ]);

        $this->assertSame('HOOK-1', $captured);
    }
}
