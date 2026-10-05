<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\AdjustStockTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAdjustment;
use App\Models\User;
use App\Services\TrackedStockReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Every path that changes products.stock without naming a location keeps the
 * per-location bins in step: decrements drain the bins in fulfilment order,
 * increments land in the primary bin, so SUM(bins) never exceeds the total.
 */
final class StockAdjustmentBinSyncTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private ProductLocation $primary;

    private ProductLocation $back;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Sync Co');
        $this->admin = $this->makeAdmin($this->org);
        $this->primary = $this->makeLocation($this->org, 'Front');
        $this->back = $this->makeLocation($this->org, 'Back');
    }

    /**
     * 10 on hand: 6 in the primary bin, 4 in the back bin.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function splitProduct(array $attributes = []): Product
    {
        $product = $this->makeProduct($this->org, $attributes + ['stock' => 10, 'location_id' => $this->primary->id]);
        $this->bin($product, $this->primary, 6);
        $this->bin($product, $this->back, 4);

        return $product;
    }

    private function bin(Product $product, ProductLocation $location, int $quantity): void
    {
        ProductLocationStock::query()->withoutGlobalScopes()->updateOrCreate(
            ['product_id' => $product->id, 'location_id' => $location->id],
            ['organization_id' => $this->org->id, 'quantity' => $quantity],
        );
    }

    /**
     * @return array{stock: int, primary: int, back: int}
     */
    private function state(Product $product): array
    {
        $bin = fn (ProductLocation $l) => (int) ProductLocationStock::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)->where('location_id', $l->id)->value('quantity');

        return [
            'stock' => (int) Product::withoutGlobalScopes()->whereKey($product->id)->value('stock'),
            'primary' => $bin($this->primary),
            'back' => $bin($this->back),
        ];
    }

    public function test_a_decrement_without_a_location_drains_the_bins_in_fulfilment_order(): void
    {
        $product = $this->splitProduct();

        StockAdjustment::adjust($product, -8, 'manual', 'Damaged', actor: $this->admin);

        $this->assertSame(['stock' => 2, 'primary' => 0, 'back' => 2], $this->state($product));
    }

    public function test_an_increment_without_a_location_lands_in_the_primary_bin(): void
    {
        $product = $this->splitProduct();

        StockAdjustment::adjust($product, 5, 'manual', 'Found', actor: $this->admin);

        $this->assertSame(['stock' => 15, 'primary' => 11, 'back' => 4], $this->state($product));
    }

    public function test_an_adjustment_naming_a_location_still_moves_only_that_bin(): void
    {
        $product = $this->splitProduct();

        StockAdjustment::adjust($product, -3, 'manual', 'Damaged', actor: $this->admin, locationId: $this->back->id);

        $this->assertSame(['stock' => 7, 'primary' => 6, 'back' => 1], $this->state($product));
    }

    public function test_a_caller_that_books_the_bins_itself_can_opt_out(): void
    {
        $product = $this->splitProduct();

        StockAdjustment::adjust($product, -3, 'manual', 'Elsewhere', actor: $this->admin, syncBins: false);

        $this->assertSame(['stock' => 7, 'primary' => 6, 'back' => 4], $this->state($product));
    }

    public function test_a_product_without_a_location_keeps_its_stock_unassigned(): void
    {
        $product = $this->makeProduct($this->org, ['stock' => 10, 'location_id' => null]);

        StockAdjustment::adjust($product, 4, 'manual', 'Found', actor: $this->admin);
        StockAdjustment::adjust($product, -6, 'manual', 'Lost', actor: $this->admin);

        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(0, ProductLocationStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->count());
    }

    public function test_rest_web_graphql_and_mcp_adjustments_without_a_location_keep_bins_in_step(): void
    {
        $product = $this->splitProduct();

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $product->id, 'quantity' => -2, 'type' => 'manual', 'reason' => 'rest',
        ])->assertCreated();
        $this->assertSame(['stock' => 8, 'primary' => 4, 'back' => 4], $this->state($product));

        $response = $this->postJson('/graphql', ['query' => "mutation { createStockAdjustment(product_id: {$product->id}, quantity: -1, type: \"manual\", reason: \"gql\") { id } }"]);
        $response->assertOk();
        $this->assertNull($response->json('errors'));
        $this->assertSame(['stock' => 7, 'primary' => 3, 'back' => 4], $this->state($product));

        InventorosServer::actingAs($this->admin)
            ->tool(AdjustStockTool::class, ['product_id' => $product->id, 'quantity' => -4, 'type' => 'manual'])
            ->assertOk();
        $this->assertSame(['stock' => 3, 'primary' => 0, 'back' => 3], $this->state($product));

        $this->actingAs($this->admin)->post(route('stock-adjustments.store'), [
            'product_id' => $product->id, 'adjustment_quantity' => 2, 'type' => 'manual', 'reason' => 'web',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['stock' => 5, 'primary' => 2, 'back' => 3], $this->state($product));
    }

    public function test_tracked_stock_reconciliation_keeps_bins_in_step(): void
    {
        $product = $this->splitProduct(['tracking_type' => 'batch']);
        ProductBatch::create(['organization_id' => $this->org->id, 'product_id' => $product->id, 'batch_number' => 'L1', 'quantity' => 7]);

        app(TrackedStockReconciliationService::class)->reconcile($this->org->id, $this->admin);

        $this->assertSame(['stock' => 7, 'primary' => 3, 'back' => 4], $this->state($product));
    }
}
